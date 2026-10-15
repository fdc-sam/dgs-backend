<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Booking\ServiceSelection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking engine: creates, cancels, reschedules and transitions appointments.
 *
 * Double-booking protection: every write that claims staff time runs in a transaction that first
 * row-locks (SELECT ... FOR UPDATE) the customer and all candidate staff profiles in a fixed order,
 * then re-computes availability from fresh data. Concurrent bookings touching the same staff are
 * serialised by those locks, so the second one sees the first one's appointment and fails with 409.
 */
class AppointmentService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PromotionService $promotions,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly PaymentService $payments,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array{branch_id: string, staff_id?: ?string, appointment_date: string, start_time: string,
     *               services: list<array{service_id: string, service_variant_id?: ?string, staff_id?: ?string}>,
     *               promotion_id?: ?string, customer_notes?: ?string}  $data
     * @param  User|null  $walkInBy  set when staff books at the counter for a customer who is there: any free start
     *                               time is allowed (no minimum-notice / booking-window rules) and the booking is
     *                               confirmed and checked in straight away
     */
    public function book(User $customer, array $data, ?User $walkInBy = null): Appointment
    {
        $branch = Branch::query()->active()->findOrFail($data['branch_id']);
        $selections = $this->resolveSelections($branch, $data['services']);
        $staff = $this->resolveStaff($data['staff_id'] ?? null);
        $date = CarbonImmutable::parse($data['appointment_date']);
        $time = substr($data['start_time'], 0, 5);
        $promotion = isset($data['promotion_id']) ? Promotion::findOrFail($data['promotion_id']) : null;
        $discount = $promotion ? $this->promotions->discountFor($promotion, $branch, $selections) : 0.0;

        $candidates = $this->candidateStaffIds($branch, $selections, $staff);
        if ($candidates === []) {
            throw new SlotUnavailableException('No staff member offers the selected services at this branch.');
        }

        $appointment = DB::transaction(function () use ($customer, $branch, $selections, $staff, $date, $time, $promotion, $discount, $candidates, $data, $walkInBy) {
            $this->lock($customer, $candidates);

            $slotStart = CarbonImmutable::parse($date->toDateString().' '.$time);
            $assignments = $this->assignStaffAt($branch, $selections, $staff, $slotStart, enforceCustomerRules: $walkInBy === null);
            if ($assignments === null) {
                throw new SlotUnavailableException;
            }
            $slotEnd = $slotStart->addMinutes($this->availability->totalDuration($selections));
            $uniqueAssignments = collect($assignments)->unique();
            $slotStaffId = $uniqueAssignments->count() === 1 ? $uniqueAssignments->first() : null;

            $this->assertCustomerIsFree($customer, $slotStart, $slotEnd);

            $subtotal = round((float) $selections->sum(fn (ServiceSelection $s) => $s->unitPrice), 2);
            $total = max(0, $subtotal - $discount);
            // Customer bookings may owe a GCash deposit first; counter bookings are paid at the salon.
            [$payment, $itemDeposits] = $walkInBy ? [[], []] : $this->payments->forNewBooking($selections, $total, $slotStart);
            $depositDue = ($payment['payment_status'] ?? null) === PaymentStatus::Awaiting;
            // A booking waiting for its deposit is confirmed when staff approve the payment, not before.
            $autoConfirm = $walkInBy !== null || ($this->settings->bool('auto_confirm_appointments') && ! $depositDue);

            $appointment = Appointment::create([
                'appointment_number' => $this->nextAppointmentNumber(),
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'staff_profile_id' => $slotStaffId,
                'promotion_id' => $promotion?->id,
                'appointment_date' => $date->toDateString(),
                'start_time' => $slotStart->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'buffer_minutes' => $this->availability->buffer($selections),
                'starts_at' => $slotStart,
                'status' => $autoConfirm ? AppointmentStatus::Confirmed : AppointmentStatus::Pending,
                'source' => $walkInBy ? Appointment::SOURCE_WALK_IN : Appointment::SOURCE_APP,
                'confirmed_at' => $autoConfirm ? now() : null,
                'checked_in_at' => $walkInBy ? now() : null,
                'checked_in_by' => $walkInBy?->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'customer_notes' => $data['customer_notes'] ?? null,
                ...$payment,
            ]);

            foreach ($selections as $index => $selection) {
                $appointment->items()->create([
                    'service_id' => $selection->service->id,
                    'service_variant_id' => $selection->variant?->id,
                    'staff_profile_id' => $assignments[$index],
                    'service_name' => $selection->service->name,
                    'variant_name' => $selection->variant?->name,
                    'quantity' => 1,
                    'unit_price' => $selection->unitPrice,
                    'duration_minutes' => $selection->durationMinutes,
                    'subtotal' => $selection->unitPrice,
                    'deposit_amount' => $itemDeposits[$index] ?? 0,
                ]);
            }

            $this->audit->log('appointment.created', $appointment, null, array_filter([
                'status' => $appointment->status->value,
                'starts_at' => $appointment->starts_at->toDateTimeString(),
                'source' => $walkInBy ? Appointment::SOURCE_WALK_IN : null,
                'by' => $walkInBy?->id,
                'deposit_amount' => $depositDue ? (float) $appointment->deposit_amount : null,
            ]));

            return $appointment;
        }, 3);

        $appointment = $this->fresh($appointment);
        // A walk-in is made by the salon itself, so the managers are not alerted about their own booking.
        if (! $walkInBy) {
            $this->notifications->appointmentCreated($appointment);
        }
        if ($appointment->status === AppointmentStatus::Confirmed) {
            $this->notifications->appointmentConfirmed($appointment);
        }

        return $appointment;
    }

    /** Staff books for a customer who is at the branch right now (see [book]). */
    public function bookWalkIn(User $customer, User $actor, array $data): Appointment
    {
        return $this->book($customer, $data, $actor);
    }

    /**
     * The customer has arrived. A pending/rescheduled booking is confirmed on the spot (the customer is told),
     * and the arrival time and the person who recorded it are saved. Doing it twice is harmless.
     */
    public function checkIn(Appointment $appointment, User $actor): Appointment
    {
        if ($appointment->checked_in_at) {
            return $appointment; // already checked in
        }
        if (! $appointment->status->isActive()) {
            throw ApiException::conflict("A {$appointment->status->label()} appointment cannot be checked in.");
        }

        $wasPending = $appointment->status !== AppointmentStatus::Confirmed;
        $old = ['status' => $appointment->status->value];
        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => $appointment->confirmed_at ?? now(),
            'checked_in_at' => now(),
            'checked_in_by' => $actor->id,
            // The customer is here: an unpaid deposit is settled at the counter, never auto-cancelled.
            'payment_due_at' => null,
        ]);
        $this->audit->log('appointment.checked_in', $appointment, $old, ['status' => 'confirmed', 'checked_in_by' => $actor->id]);

        $appointment = $this->fresh($appointment);
        if ($wasPending) {
            $this->notifications->appointmentConfirmed($appointment);
        }

        return $appointment;
    }

    public function cancel(Appointment $appointment, User $actor, ?string $reason = null): Appointment
    {
        $isCustomer = $actor->id === $appointment->customer_id && $actor->isCustomer();

        if ($isCustomer && ! $this->canCustomerCancel($appointment)) {
            throw ApiException::conflict($this->customerChangeBlockedReason($appointment, 'cancelled'));
        }
        if (! $appointment->status->canTransitionTo(AppointmentStatus::Cancelled)) {
            throw ApiException::conflict("A {$appointment->status->label()} appointment cannot be cancelled.");
        }

        $old = ['status' => $appointment->status->value];
        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancellation_reason' => $reason,
            // Nothing was paid yet, so nothing is owed either way. A receipt under review stays for staff to settle.
            ...($appointment->payment_status->acceptsProof() ? ['payment_status' => PaymentStatus::Void, 'payment_due_at' => null] : []),
        ]);
        $this->audit->log('appointment.cancelled', $appointment, $old, ['status' => 'cancelled', 'reason' => $reason]);

        $appointment = $this->fresh($appointment);
        $this->notifications->appointmentCancelled($appointment, $actor);

        return $appointment;
    }

    /**
     * Move an appointment to a new date/time. Customers must pick a grid slot that respects booking rules;
     * staff/admins may choose any free start time (minimum-notice rules are relaxed for them).
     * When $staffId is omitted the current staff member is kept.
     */
    public function reschedule(Appointment $appointment, User $actor, string $date, string $startTime, ?string $staffId = null, bool $keepStaff = true): Appointment
    {
        $isCustomer = $actor->id === $appointment->customer_id && $actor->isCustomer();

        if ($isCustomer && ! $this->canCustomerReschedule($appointment)) {
            throw ApiException::conflict($this->customerChangeBlockedReason($appointment, 'rescheduled'));
        }
        if (! $appointment->status->canTransitionTo(AppointmentStatus::Rescheduled)) {
            throw ApiException::conflict("A {$appointment->status->label()} appointment cannot be rescheduled.");
        }

        $appointment->loadMissing(['items.service.branches', 'items.variant', 'branch', 'customer']);
        $branch = $appointment->branch;
        $selections = $this->selectionsFromItems($appointment);
        if ($staffId) {
            $selections = $selections->map(fn (ServiceSelection $s) => new ServiceSelection(
                $s->service, $s->variant, $s->unitPrice, $s->durationMinutes, $s->bufferMinutes, $staffId,
            ));
        }
        $staff = $staffId ? null : ($keepStaff ? $appointment->staff : null);
        $day = CarbonImmutable::parse($date);
        $start = CarbonImmutable::parse($day->toDateString().' '.substr($startTime, 0, 5));

        $candidates = $this->candidateStaffIds($branch, $selections, $staff);
        if ($candidates === []) {
            throw new SlotUnavailableException('The selected staff member cannot perform these services at this branch.');
        }

        $old = [
            'starts_at' => $appointment->starts_at->toDateTimeString(),
            'staff_profile_id' => $appointment->staff_profile_id,
            'status' => $appointment->status->value,
        ];

        DB::transaction(function () use ($appointment, $isCustomer, $branch, $selections, $staff, $day, $start, $candidates) {
            $this->lock($appointment->customer, $candidates);

            $assignments = $this->assignStaffAt($branch, $selections, $staff, $start, $appointment->id, enforceCustomerRules: $isCustomer);
            if ($assignments === null) {
                throw new SlotUnavailableException;
            }

            $end = $start->addMinutes($this->availability->totalDuration($selections));
            $this->assertCustomerIsFree($appointment->customer, $start, $end, $appointment->id);
            $assignedStaff = collect($assignments)->unique();

            $appointment->update([
                'appointment_date' => $day->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'starts_at' => $start,
                'staff_profile_id' => $assignedStaff->count() === 1 ? $assignedStaff->first() : null,
                'status' => AppointmentStatus::Rescheduled,
                'confirmed_at' => null,
            ]);
            foreach ($appointment->items as $index => $item) {
                $item->update(['staff_profile_id' => $assignments[$index]]);
            }
            // Reminders are relative to the new time.
            $appointment->reminders()->delete();
        }, 3);

        $this->audit->log('appointment.rescheduled', $appointment, $old, [
            'starts_at' => $appointment->starts_at->toDateTimeString(),
            'staff_profile_id' => $appointment->staff_profile_id,
            'status' => AppointmentStatus::Rescheduled->value,
        ]);

        $appointment = $this->fresh($appointment);
        $this->notifications->appointmentRescheduled($appointment, $actor);

        return $appointment;
    }

    /** Staff/admin status change: confirm, start, complete, no-show. */
    public function transition(Appointment $appointment, AppointmentStatus $to, User $actor): Appointment
    {
        if ($to === AppointmentStatus::Cancelled || $to === AppointmentStatus::Rescheduled) {
            throw new \InvalidArgumentException('Use cancel() or reschedule() for this transition.');
        }
        if (! $appointment->status->canTransitionTo($to)) {
            throw ApiException::conflict("Cannot change a {$appointment->status->label()} appointment to {$to->label()}.");
        }

        $old = ['status' => $appointment->status->value];
        DB::transaction(function () use ($appointment, $to) {
            $appointment->update([
                ...array_filter([
                    'status' => $to,
                    'confirmed_at' => $to === AppointmentStatus::Confirmed ? now() : null,
                    'completed_at' => $to === AppointmentStatus::Completed ? now() : null,
                ]),
                // Staff took over (e.g. confirmed without a deposit): the booking is no longer released automatically.
                'payment_due_at' => null,
            ]);
            if ($to === AppointmentStatus::Completed) {
                $this->inventory->recordUsage($appointment->items);
            }
        });
        $this->audit->log('appointment.'.$to->value, $appointment, $old, ['status' => $to->value]);

        $appointment = $this->fresh($appointment);
        match ($to) {
            AppointmentStatus::Confirmed => $this->notifications->appointmentConfirmed($appointment),
            AppointmentStatus::Completed => $this->notifications->appointmentCompleted($appointment),
            default => null,
        };

        return $appointment;
    }

    public function canCustomerCancel(Appointment $appointment): bool
    {
        // An unpaid hold can always be released by the customer; nothing has been paid.
        return $appointment->status->isActive()
            && ($this->outsideCancellationWindow($appointment) || $appointment->payment_status->acceptsProof());
    }

    public function canCustomerReschedule(Appointment $appointment): bool
    {
        return $appointment->status->isActive() && $this->outsideCancellationWindow($appointment);
    }

    public function canBeReviewed(Appointment $appointment): bool
    {
        return $appointment->status === AppointmentStatus::Completed && ! $appointment->review()->exists();
    }

    /**
     * @param  list<array{service_id: string, service_variant_id?: ?string}>  $services
     * @return Collection<int, ServiceSelection>
     */
    public function resolveSelections(Branch $branch, array $services): Collection
    {
        $models = Service::query()->active()
            ->whereKey(array_column($services, 'service_id'))
            ->with(['branches' => fn ($q) => $q->whereKey($branch->id)])
            ->get()->keyBy('id');

        return collect($services)->values()->map(function (array $row, int $i) use ($models, $branch) {
            $service = $models->get($row['service_id']);
            $pivot = $service?->branches->first()?->pivot;

            if (! $service || ! $pivot || ! $pivot->is_available) {
                throw ValidationException::withMessages(["services.{$i}.service_id" => 'This service is not available at the selected branch.']);
            }

            $variant = null;
            if (! empty($row['service_variant_id'])) {
                $variant = ServiceVariant::query()->active()->where('service_id', $service->id)->find($row['service_variant_id']);
                if (! $variant) {
                    throw ValidationException::withMessages(["services.{$i}.service_variant_id" => 'The selected option is not valid for this service.']);
                }
            }

            $staffId = $row['staff_id'] ?? null;
            if ($staffId && ! StaffProfile::query()->bookable()->atBranch($branch->id)->offersService($service->id)->whereKey($staffId)->exists()) {
                throw ValidationException::withMessages(["services.{$i}.staff_id" => 'Choose a stylist who offers this service at the selected branch.']);
            }

            return ServiceSelection::resolve($service, $variant, $branch, $staffId);
        });
    }

    /** Rebuild selections from an existing appointment's snapshot (for rescheduling). */
    private function selectionsFromItems(Appointment $appointment): Collection
    {
        return $appointment->items->map(function ($item) {
            if (! $item->service) {
                throw ApiException::conflict('A service in this appointment no longer exists. Please book a new appointment.');
            }

            return new ServiceSelection(
                $item->service,
                $item->variant,
                (float) $item->unit_price,
                $item->duration_minutes,
                $item->service->buffer_minutes,
                $item->staff_profile_id,
            );
        })->values();
    }

    /** @param Collection<int, ServiceSelection> $selections @return list<string> */
    private function candidateStaffIds(Branch $branch, Collection $selections, ?StaffProfile $globalStaff): array
    {
        $ids = [];
        foreach ($selections as $selection) {
            $preferredId = $selection->staffProfileId ?? $globalStaff?->id;
            $preferred = $preferredId ? $this->resolveStaff($preferredId) : null;
            $qualified = $this->availability->qualifiedStaffIds($branch, collect([$selection]), $preferred);
            if ($qualified === []) {
                throw ValidationException::withMessages([
                    'services' => "No available stylist offers {$selection->service->name} at this branch.",
                ]);
            }
            $ids = [...$ids, ...$qualified];
        }

        return array_values(array_unique($ids));
    }

    /** @param Collection<int, ServiceSelection> $selections @return list<string>|null */
    private function assignStaffAt(
        Branch $branch,
        Collection $selections,
        ?StaffProfile $globalStaff,
        CarbonImmutable $start,
        ?string $excludeAppointmentId = null,
        bool $enforceCustomerRules = true,
    ): ?array {
        $assignments = [];
        $offset = 0;
        foreach ($selections as $selection) {
            $preferredId = $selection->staffProfileId ?? $globalStaff?->id;
            $preferred = $preferredId ? $this->resolveStaff($preferredId) : null;
            $serviceStart = $start->addMinutes($offset);
            $free = $this->availability->freeStaffAt(
                $branch,
                collect([$selection]),
                $preferred,
                $serviceStart,
                $excludeAppointmentId,
                $enforceCustomerRules,
            );
            if ($free === []) {
                return null;
            }
            $assignments[] = $free[0];
            $offset += $selection->durationMinutes;
        }

        return $assignments;
    }

    private function resolveStaff(?string $staffId): ?StaffProfile
    {
        if (! $staffId) {
            return null;
        }

        return StaffProfile::query()->bookable()->find($staffId)
            ?? throw ValidationException::withMessages(['staff_id' => 'The selected staff member is not available.']);
    }

    /** Locks acquired in a fixed order (customer, then staff by id) to avoid deadlocks. */
    private function lock(User $customer, array $staffIds): void
    {
        User::query()->whereKey($customer->id)->lockForUpdate()->first();
        sort($staffIds);
        StaffProfile::query()->whereKey($staffIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertCustomerIsFree(User $customer, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreId = null): void
    {
        $overlap = Appointment::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Rescheduled->value, AppointmentStatus::InProgress->value])
            ->whereDate('appointment_date', $start->toDateString())
            ->where('start_time', '<', $end->format('H:i:s'))
            ->where('end_time', '>', $start->format('H:i:s'))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($overlap) {
            throw ApiException::conflict('You already have an appointment at this time.');
        }
    }

    /** DG-YYYYMMDD-NNNNNN using a row-locked daily counter (must run inside a transaction). */
    private function nextAppointmentNumber(): string
    {
        $today = now()->format('Ymd');
        $key = "appointment:{$today}";

        DB::table('sequences')->insertOrIgnore(['key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $value = (int) DB::table('sequences')->where('key', $key)->lockForUpdate()->value('value') + 1;
        DB::table('sequences')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);

        return sprintf('DG-%s-%06d', $today, $value);
    }

    private function outsideCancellationWindow(Appointment $appointment): bool
    {
        return now()->addHours($this->settings->int('cancellation_hours', 24))->lte($appointment->starts_at);
    }

    private function customerChangeBlockedReason(Appointment $appointment, string $verb): string
    {
        if (! $appointment->status->isActive()) {
            return "A {$appointment->status->label()} appointment cannot be {$verb}.";
        }

        $hours = $this->settings->int('cancellation_hours', 24);

        return "Appointments can only be {$verb} at least {$hours} hours before the start time. Please contact the salon.";
    }

    private function fresh(Appointment $appointment): Appointment
    {
        return $appointment->fresh(['customer', 'branch', 'staff.user', 'promotion', 'items.staff.user', 'review.images', 'latestPayment']);
    }
}
