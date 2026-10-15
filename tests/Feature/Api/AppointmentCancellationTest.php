<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AppointmentCancellationTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    private function appointmentFor(User $customer, string $at = '2026-10-03 10:00', AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        return Appointment::factory()->at($at)->status($status)->create([
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'staff_profile_id' => $this->stylist->id,
        ]);
    }

    public function test_customer_cancels_own_appointment_and_slot_frees_up(): void
    {
        Notification::fake();
        User::factory()->admin()->create();
        $customer = $this->actingAsCustomer();
        $appointment = $this->appointmentFor($customer, self::DAY.' 09:00');
        $this->assertNotContains('09:00', $this->availableTimes());

        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel", ['reason' => 'Change of plans'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Change of plans')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'cancelled_by' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.cancelled', 'entity_id' => $appointment->id]);
        $this->assertContains('09:00', $this->availableTimes());
        Notification::assertSentTo(User::managers()->first(), AppointmentNotification::class);
    }

    public function test_cannot_cancel_inside_cancellation_window(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->appointmentFor($customer, '2026-10-01 20:00'); // 12 h away, window 24 h

        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_cannot_cancel_completed_or_cancelled(): void
    {
        $customer = $this->actingAsCustomer();

        foreach ([AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $status) {
            $appointment = $this->appointmentFor($customer, '2026-10-10 10:00', $status);
            $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")->assertStatus(409);
        }
    }

    public function test_cannot_cancel_someone_elses_appointment(): void
    {
        $appointment = $this->appointmentFor($this->customer());
        $this->actingAsCustomer();

        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }
}
