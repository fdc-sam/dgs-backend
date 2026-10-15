<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::tomorrow()->setTime(10, 0);

        return [
            'appointment_number' => 'DG-'.$start->format('Ymd').'-'.fake()->unique()->numerify('9#####'),
            'customer_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'staff_profile_id' => null,
            'appointment_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->addHour()->format('H:i:s'),
            'buffer_minutes' => 0,
            'starts_at' => $start,
            'status' => AppointmentStatus::Pending,
            'subtotal' => 500,
            'discount_amount' => 0,
            'total_amount' => 500,
        ];
    }

    /** Place the appointment at a specific local date/time. */
    public function at(string $dateTime, int $durationMinutes = 60, int $bufferMinutes = 0): static
    {
        $start = CarbonImmutable::parse($dateTime);

        return $this->state(fn () => [
            'appointment_number' => 'DG-'.$start->format('Ymd').'-'.fake()->unique()->numerify('9#####'),
            'appointment_date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->addMinutes($durationMinutes)->format('H:i:s'),
            'buffer_minutes' => $bufferMinutes,
            'starts_at' => $start,
        ]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(fn () => array_filter([
            'status' => $status,
            'confirmed_at' => in_array($status, [AppointmentStatus::Confirmed, AppointmentStatus::Completed], true) ? now() : null,
            'completed_at' => $status === AppointmentStatus::Completed ? now() : null,
            'cancelled_at' => $status === AppointmentStatus::Cancelled ? now() : null,
        ], fn ($v) => $v !== null));
    }

    public function completed(): static
    {
        return $this->status(AppointmentStatus::Completed);
    }

    public function confirmed(): static
    {
        return $this->status(AppointmentStatus::Confirmed);
    }
}
