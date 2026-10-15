<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_24h_and_2h_reminders_once_each(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00'));
        $appointment = Appointment::factory()->at('2026-10-03 10:00')->confirmed()->create();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00')); // 25 h before
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:05')); // 23:55 h before
        $this->artisan('appointments:send-reminders');
        $this->artisan('appointments:send-reminders'); // re-run: no duplicate
        Notification::assertSentToTimes($appointment->customer, AppointmentNotification::class, 1);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:01')); // 1:59 h before
        $this->artisan('appointments:send-reminders');
        Notification::assertSentToTimes($appointment->customer, AppointmentNotification::class, 2);
        Notification::assertSentTo($appointment->customer, AppointmentNotification::class, fn ($n) => $n->type === NotificationType::AppointmentReminder);
    }

    public function test_skips_reminder_whose_moment_passed_before_booking_and_cancelled(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 05:00'));
        $late = Appointment::factory()->at('2026-10-03 10:00')->create(); // booked 5 h before
        Appointment::factory()->at('2026-10-03 11:00')->status(AppointmentStatus::Cancelled)->create();

        $this->artisan('appointments:send-reminders');
        Notification::assertNothingSent(); // 24 h moment passed before booking; 2 h not due yet

        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:30'));
        $this->artisan('appointments:send-reminders');
        Notification::assertSentToTimes($late->customer, AppointmentNotification::class, 1);
    }
}
