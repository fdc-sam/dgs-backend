<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AppointmentRescheduleTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $owner;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        $this->owner = $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/appointments', $this->bookingPayload(['appointment_date' => '2026-10-03', 'start_time' => '10:00']))
            ->assertCreated()->json('data.id');
        $this->appointment = Appointment::findOrFail($id);
    }

    public function test_customer_reschedules_to_free_slot(): void
    {
        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-04',
            'start_time' => '14:00',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.appointment_date', '2026-10-04')
            ->assertJsonPath('data.start_time', '14:00')
            ->assertJsonPath('data.end_time', '15:00')
            ->assertJsonPath('data.staff.id', $this->stylist->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'entity_id' => $this->appointment->id]);
    }

    public function test_can_shift_within_own_time_range(): void
    {
        // 10:30 overlaps the current 10:00–11:15 occupancy but that is the appointment being moved.
        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-03', 'start_time' => '10:30',
        ])->assertOk()->assertJsonPath('data.start_time', '10:30');
    }

    public function test_availability_can_exclude_own_appointment(): void
    {
        $query = ['staff_id' => $this->stylist->id, 'date' => '2026-10-03'];
        $this->assertNotContains('10:00', $this->availableTimes($query));
        $this->assertContains('10:00', $this->availableTimes($query + ['exclude_appointment_id' => $this->appointment->id]));
    }

    public function test_cannot_reschedule_into_taken_slot(): void
    {
        Appointment::factory()->at('2026-10-04 14:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-04', 'start_time' => '14:00',
        ])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');

        $this->assertSame('2026-10-03', $this->appointment->fresh()->appointment_date->toDateString());
    }

    public function test_reschedule_to_other_staff(): void
    {
        $other = $this->makeStylist();

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-04', 'start_time' => '09:00', 'staff_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.staff.id', $other->id);
    }

    public function test_cannot_reschedule_inside_window_or_final_status(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(0, 0)); // 10 h before start
        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-05', 'start_time' => '09:00',
        ])->assertStatus(409)->assertJsonPath('code', 'CONFLICT');

        $this->travelBack();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(8, 0));
        $this->appointment->update(['status' => AppointmentStatus::Completed]);
        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-05', 'start_time' => '09:00',
        ])->assertStatus(409);
    }

    public function test_other_customer_cannot_reschedule(): void
    {
        $this->actingAsCustomer();

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-04', 'start_time' => '09:00',
        ])->assertForbidden();
    }

    public function test_reminders_reset_after_reschedule(): void
    {
        $this->appointment->reminders()->create(['offset_minutes' => 1440, 'sent_at' => now()]);

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", [
            'appointment_date' => '2026-10-04', 'start_time' => '09:00',
        ])->assertOk();

        $this->assertSame(0, $this->appointment->reminders()->count());
    }
}
