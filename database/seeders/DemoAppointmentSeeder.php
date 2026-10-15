<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Models\Branch;
use App\Models\Review;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Support\Booking\ServiceSelection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEVELOPMENT ONLY — fills the last 3 weeks and next 2 weeks with sample bookings
 * (respecting staff schedules) so the dashboard, calendar and reports have something to show.
 */
class DemoAppointmentSeeder extends Seeder
{
    public function run(AvailabilityService $availability): void
    {
        if (DB::table('appointments')->exists()) {
            return;
        }

        mt_srand(2026);
        $customers = User::factory()->count(12)->customer()->create()->push(User::where('email', 'customer@example.com')->first())->filter();
        $counter = [];

        foreach (Branch::with('services.branches')->get() as $branch) {
            $staffList = StaffProfile::query()->atBranch($branch->id)->with('services')->get();

            for ($offset = -21; $offset <= 14; $offset++) {
                $date = now()->startOfDay()->addDays($offset);

                foreach ($staffList as $staff) {
                    $services = $staff->services->whereIn('id', $branch->services->pluck('id'));
                    if ($services->isEmpty()) {
                        continue;
                    }

                    $cursor = null;
                    for ($n = 0; $n < mt_rand(1, 4); $n++) {
                        $service = $services->random();
                        $selection = collect([ServiceSelection::resolve($service->load('branches'), null, $branch)]);
                        $day = $availability->forDay($branch, $selection, $staff, $date);
                        $free = collect($day->slots)->filter(fn ($s) => in_array($staff->id, $s->staffIds, true) && (! $cursor || $s->start->gte($cursor)));

                        // Past days: pick from the schedule grid ignoring "now".
                        if ($offset < 0) {
                            $free = $this->pastSlots($date, $staff, $branch, $selection, $cursor);
                        }
                        if ($free->isEmpty()) {
                            break;
                        }

                        $slot = $free->values()->get(mt_rand(0, min(2, $free->count() - 1)));
                        $start = $slot instanceof CarbonImmutable ? $slot : $slot->start;
                        $duration = $selection->first()->durationMinutes;
                        $status = $offset < 0
                            ? collect([...array_fill(0, 7, AppointmentStatus::Completed), AppointmentStatus::Cancelled, AppointmentStatus::NoShow])->random()
                            : collect([AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Confirmed])->random();
                        $key = now()->subDays(max(0, -$offset))->format('Ymd');
                        $counter[$key] = ($counter[$key] ?? 0) + 1;
                        $price = $selection->first()->unitPrice;

                        $appointment = $staff->appointments()->create([
                            'appointment_number' => sprintf('DG-%s-%06d', $key, 900000 + $counter[$key]),
                            'customer_id' => $customers->random()->id,
                            'branch_id' => $branch->id,
                            'appointment_date' => $date->toDateString(),
                            'start_time' => $start->format('H:i:s'),
                            'end_time' => $start->addMinutes($duration)->format('H:i:s'),
                            'buffer_minutes' => $service->buffer_minutes,
                            'starts_at' => $start,
                            'status' => $status,
                            'subtotal' => $price,
                            'discount_amount' => 0,
                            'total_amount' => $price,
                            'confirmed_at' => $status !== AppointmentStatus::Pending ? $start->subDay() : null,
                            'completed_at' => $status === AppointmentStatus::Completed ? $start->addMinutes($duration) : null,
                            'cancelled_at' => $status === AppointmentStatus::Cancelled ? $start->subDay() : null,
                        ]);
                        $appointment->items()->create([
                            'service_id' => $service->id, 'service_name' => $service->name, 'quantity' => 1,
                            'unit_price' => $price, 'duration_minutes' => $duration, 'subtotal' => $price,
                            'product_cost' => $status === AppointmentStatus::Completed ? $service->productCost() : 0,
                        ]);

                        if ($status === AppointmentStatus::Completed && mt_rand(0, 2) === 0) {
                            Review::create([
                                'appointment_id' => $appointment->id, 'customer_id' => $appointment->customer_id, 'service_id' => $service->id,
                                'rating' => mt_rand(4, 5), 'review' => 'Sample review for development.', 'status' => mt_rand(0, 3) ? 'approved' : 'pending',
                            ]);
                        }

                        $cursor = $start->addMinutes($duration + $service->buffer_minutes);
                    }
                }
            }
        }
    }

    /** Schedule-grid starts for a past date (no minimum-notice rule), after $cursor. */
    private function pastSlots(CarbonImmutable $date, StaffProfile $staff, Branch $branch, $selection, ?CarbonImmutable $cursor)
    {
        $schedule = $staff->schedules()->where('day_of_week', $date->dayOfWeek)->where('branch_id', $branch->id)->first();
        if (! $schedule) {
            return collect();
        }
        $length = $selection->first()->durationMinutes + $selection->first()->bufferMinutes;
        $slots = collect();
        for ($t = CarbonImmutable::parse($date->toDateString().' '.$schedule->start_time); $t->addMinutes($length)->lte(CarbonImmutable::parse($date->toDateString().' '.$schedule->end_time)); $t = $t->addMinutes(30)) {
            $inBreak = $schedule->break_start && $t->lt(CarbonImmutable::parse($date->toDateString().' '.$schedule->break_end)) && $t->addMinutes($length)->gt(CarbonImmutable::parse($date->toDateString().' '.$schedule->break_start));
            if (! $inBreak && (! $cursor || $t->gte($cursor))) {
                $slots->push($t);
            }
        }

        return $slots;
    }
}
