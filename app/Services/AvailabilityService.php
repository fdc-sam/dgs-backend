<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Support\Booking\DaySchedule;
use App\Support\Booking\Interval;
use App\Support\Booking\ServiceSelection;
use App\Support\Booking\Slot;
use App\Support\Booking\StaffDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes bookable time slots.
 *
 * A slot [start, start + duration) is available when at least one qualified staff member can take
 * the occupancy [start, start + duration + buffer) and all of these hold:
 *  - the date is within [today, today + booking_maximum_days] and start >= now + minimum notice
 *  - the branch is open that weekday, not closed that date, and the occupancy fits its hours
 *  - the staff member works at the branch that weekday, is not on a day off, the occupancy fits
 *    the shift, does not touch the break, and does not overlap any blocking appointment (+ its buffer)
 */
class AvailabilityService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  Collection<int, ServiceSelection>  $selections
     */
    public function forDay(
        Branch $branch,
        Collection $selections,
        ?StaffProfile $staff,
        CarbonImmutable $date,
        ?string $excludeAppointmentId = null,
        bool $enforceCustomerRules = true,
    ): DaySchedule {
        $date = $date->startOfDay();
        $duration = $this->totalDuration($selections);
        $buffer = $this->buffer($selections);
        $closed = fn (string $reason) => new DaySchedule($date, $branch->id, $selections->first()?->service->id, $staff?->id, $duration, false, $reason, []);

        // Staff booking at the counter ignore the customer-facing rules (booking window, minimum notice),
        // but never the past and never opening hours / staff schedules.
        if ($reason = ($enforceCustomerRules ? $this->windowViolation($date) : $this->pastViolation($date))) {
            return $closed($reason);
        }

        $hours = $this->branchWindow($branch, $date);
        if (is_string($hours)) {
            return $closed($hours);
        }

        $staffDays = $this->staffDays($branch, $selections, $staff, $date, $excludeAppointmentId);
        $interval = max(5, $this->settings->int('booking_slot_interval_minutes', 30));
        $earliest = $enforceCustomerRules ? now()->addHours($this->settings->int('booking_minimum_notice_hours', 0)) : now();

        $slots = [];
        for ($start = $hours->start; $start->addMinutes($duration + $buffer)->lte($hours->end); $start = $start->addMinutes($interval)) {
            $occupancy = new Interval($start, $start->addMinutes($duration + $buffer));
            $free = $start->lt($earliest) ? [] : $this->freeStaff($staffDays, $occupancy);
            $slots[] = new Slot($start, $start->addMinutes($duration), $free !== [], $free);
        }

        return new DaySchedule($date, $branch->id, $selections->first()?->service->id, $staff?->id, $duration, true, $staffDays === [] ? 'No staff available on this date.' : null, $slots);
    }

    /**
     * Staff IDs free for an exact start time (not restricted to the slot grid). Used by the booking
     * engine inside its locked transaction and by admin rescheduling.
     *
     * @param  Collection<int, ServiceSelection>  $selections
     * @return list<string>
     */
    public function freeStaffAt(
        Branch $branch,
        Collection $selections,
        ?StaffProfile $staff,
        CarbonImmutable $start,
        ?string $excludeAppointmentId = null,
        bool $enforceCustomerRules = true,
    ): array {
        $date = $start->startOfDay();

        if ($enforceCustomerRules) {
            if ($this->windowViolation($date)) {
                return [];
            }
            if ($start->lt(now()->addHours($this->settings->int('booking_minimum_notice_hours', 0)))) {
                return [];
            }
        } elseif ($start->lt(now())) {
            return [];
        }

        $hours = $this->branchWindow($branch, $date);
        if (is_string($hours)) {
            return [];
        }

        $occupancy = new Interval($start, $start->addMinutes($this->totalDuration($selections) + $this->buffer($selections)));
        if (! $hours->contains($occupancy)) {
            return [];
        }

        return $this->freeStaff($this->staffDays($branch, $selections, $staff, $date, $excludeAppointmentId), $occupancy);
    }

    /**
     * Per-date availability summary for a month (booking calendar).
     *
     * @param  Collection<int, ServiceSelection>  $selections
     * @return list<array{date: string, available: bool}>
     */
    public function forMonth(Branch $branch, Collection $selections, ?StaffProfile $staff, CarbonImmutable $month, ?string $excludeAppointmentId = null): array
    {
        $days = [];
        for ($day = $month->startOfMonth(); $day->lte($month->endOfMonth()); $day = $day->addDay()) {
            $schedule = $this->forDay($branch, $selections, $staff, $day, $excludeAppointmentId);
            $days[] = ['date' => $day->toDateString(), 'available' => $schedule->hasAvailableSlot()];
        }

        return $days;
    }

    /**
     * Qualified, bookable staff IDs for the branch and all selected services (optionally one staff).
     *
     * @param  Collection<int, ServiceSelection>  $selections
     * @return list<string>
     */
    public function qualifiedStaffIds(Branch $branch, Collection $selections, ?StaffProfile $staff = null): array
    {
        $query = StaffProfile::query()->bookable()->atBranch($branch->id);
        foreach ($selections->map(fn (ServiceSelection $s) => $s->service->id)->unique() as $serviceId) {
            $query->offersService($serviceId);
        }
        if ($staff) {
            $query->whereKey($staff->id);
        }

        return $query->orderBy('staff_profiles.id')->pluck('staff_profiles.id')->all();
    }

    /** @param  Collection<int, ServiceSelection>  $selections */
    public function totalDuration(Collection $selections): int
    {
        return (int) $selections->sum(fn (ServiceSelection $s) => $s->durationMinutes);
    }

    /** Clean-up time after the whole appointment: the largest buffer among its services. */
    public function buffer(Collection $selections): int
    {
        return (int) ($selections->max(fn (ServiceSelection $s) => $s->bufferMinutes) ?? 0);
    }

    private function pastViolation(CarbonImmutable $date): ?string
    {
        return $date->lt(now()->startOfDay()) ? 'This date has already passed.' : null;
    }

    private function windowViolation(CarbonImmutable $date): ?string
    {
        $today = now()->startOfDay();

        if ($date->lt($today)) {
            return 'This date has already passed.';
        }
        if ($date->gt($today->addDays($this->settings->int('booking_maximum_days', 60)))) {
            return 'This date is beyond the booking window.';
        }

        return null;
    }

    /** Opening interval for the date, or the reason the branch is closed. */
    private function branchWindow(Branch $branch, CarbonImmutable $date): Interval|string
    {
        if (! $branch->isActive()) {
            return 'This branch is not accepting bookings.';
        }

        $closure = $branch->closures()->whereDate('date', $date)->first();
        if ($closure) {
            return $closure->reason ?: 'The branch is closed on this date.';
        }

        $row = $branch->businessHours()->where('day_of_week', $date->dayOfWeek)->first();
        if ($row && ! $row->is_open) {
            return 'The branch is closed on this day.';
        }

        $open = $row?->opening_time ?? $branch->opening_time;
        $close = $row?->closing_time ?? $branch->closing_time;
        if (! $open || ! $close || $open >= $close) {
            return 'The branch is closed on this day.';
        }

        return new Interval($this->at($date, $open), $this->at($date, $close));
    }

    /**
     * @param  Collection<int, ServiceSelection>  $selections
     * @return list<StaffDay>
     */
    private function staffDays(Branch $branch, Collection $selections, ?StaffProfile $staff, CarbonImmutable $date, ?string $excludeAppointmentId): array
    {
        $ids = $this->qualifiedStaffIds($branch, $selections, $staff);
        if ($ids === []) {
            return [];
        }

        $profiles = StaffProfile::query()
            ->whereKey($ids)
            ->with([
                'schedules' => fn ($q) => $q->where('day_of_week', $date->dayOfWeek)->where('branch_id', $branch->id)->where('is_working', true),
                'daysOff' => fn ($q) => $q->whereDate('date', $date)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id)),
                'branches' => fn ($q) => $q->whereKey($branch->id),
            ])
            ->orderBy('id')
            ->get();

        // Existing bookings across ALL branches: a person cannot be in two places at once.
        $busy = Appointment::query()
            ->whereIn('staff_profile_id', $ids)
            ->whereDate('appointment_date', $date)
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->when($excludeAppointmentId, fn ($q) => $q->whereKeyNot($excludeAppointmentId))
            ->get(['id', 'staff_profile_id', 'start_time', 'end_time', 'buffer_minutes'])
            ->groupBy('staff_profile_id');

        $busyItems = AppointmentItem::query()->whereIn('staff_profile_id', $ids)
            ->whereHas('appointment', fn ($query) => $query
                ->whereDate('appointment_date', $date)
                ->whereIn('status', AppointmentStatus::blockingValues())
                ->when($excludeAppointmentId, fn ($q) => $q->whereKeyNot($excludeAppointmentId)))
            ->with(['appointment' => fn ($query) => $query->select(['id', 'appointment_date', 'start_time', 'end_time', 'buffer_minutes'])])
            ->get(['id', 'appointment_id', 'staff_profile_id']);

        $busyIntervalsByStaff = [];
        foreach ($busy as $staffId => $appointments) {
            foreach ($appointments as $appointment) {
                $busyIntervalsByStaff[$staffId][] = new Interval(
                    $this->at($date, $appointment->start_time),
                    $this->at($date, $appointment->end_time)->addMinutes($appointment->buffer_minutes),
                );
            }
        }
        foreach ($busyItems as $item) {
            $appointment = $item->appointment;
            if (! $appointment) {
                continue;
            }
            $busyIntervalsByStaff[$item->staff_profile_id][] = new Interval(
                $this->at($date, $appointment->start_time),
                $this->at($date, $appointment->end_time)->addMinutes($appointment->buffer_minutes),
            );
        }

        $days = [];
        foreach ($profiles as $profile) {
            $schedule = $profile->schedules->first();
            if (! $schedule || ! $schedule->start_time || ! $schedule->end_time || $profile->daysOff->isNotEmpty()) {
                continue;
            }

            $break = $schedule->break_start && $schedule->break_end && $schedule->break_start < $schedule->break_end
                ? new Interval($this->at($date, $schedule->break_start), $this->at($date, $schedule->break_end))
                : null;

            $days[] = new StaffDay(
                staffId: $profile->id,
                shift: new Interval($this->at($date, $schedule->start_time), $this->at($date, $schedule->end_time)),
                break: $break,
                busy: $busyIntervalsByStaff[$profile->id] ?? [],
                primaryAtBranch: (bool) $profile->branches->first()?->pivot?->is_primary,
            );
        }

        return $days;
    }

    /**
     * Free staff for an occupancy, ordered so "any staff" bookings spread load:
     * fewest bookings that day first, then staff whose primary branch this is.
     *
     * @param  list<StaffDay>  $staffDays
     * @return list<string>
     */
    private function freeStaff(array $staffDays, Interval $occupancy): array
    {
        $free = array_values(array_filter($staffDays, fn (StaffDay $d) => $d->isFree($occupancy)));

        usort($free, fn (StaffDay $a, StaffDay $b) => [count($a->busy), ! $a->primaryAtBranch, $a->staffId]
            <=> [count($b->busy), ! $b->primaryAtBranch, $b->staffId]);

        return array_map(fn (StaffDay $d) => $d->staffId, $free);
    }

    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$time);
    }
}
