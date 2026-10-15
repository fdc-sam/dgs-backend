<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Service;
use App\Models\StaffDayOff;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_returns_slot_grid_respecting_duration_buffer_break_and_closing(): void
    {
        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => self::DAY,
        ]))->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.duration_minutes', 60);

        $slots = collect($response->json('data.slots'));
        $this->assertSame(['start_time' => '09:00', 'end_time' => '10:00', 'available' => true], $slots->first());

        // 60 + 15 buffer = 75 minutes must fit before 18:00 → last start 16:30; 17:00 is not offered at all.
        $this->assertSame('16:30', $slots->last()['start_time']);

        $available = $slots->where('available', true)->pluck('start_time')->all();
        // Break 12:00–13:00: 10:45+ occupancy overlaps → 11:00, 11:30, 12:00, 12:30 unavailable.
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'], $available);
    }

    public function test_salon_closing_example_from_spec(): void
    {
        // Spec: 60 min + 15 buffer, salon closes at 18:00 → 17:30 must not be available.
        $this->assertNotContains('17:30', $this->availableTimes());
        $this->assertNotContains('17:00', $this->availableTimes());
    }

    public function test_existing_appointment_and_its_buffer_block_the_staff(): void
    {
        Appointment::factory()->at(self::DAY.' 14:00', 60, 15)->create([
            'branch_id' => $this->branch->id,
            'staff_profile_id' => $this->stylist->id,
        ]);

        $times = $this->availableTimes(['staff_id' => $this->stylist->id]);

        // Busy 14:00–15:15. A new occupancy [s, s+75) must not overlap it.
        $this->assertNotContains('13:00', $times); // 13:00–14:15 overlaps
        $this->assertNotContains('14:00', $times);
        $this->assertNotContains('15:00', $times); // starts inside buffer
        $this->assertContains('15:30', $times);
    }

    public function test_cancelled_and_no_show_appointments_do_not_block(): void
    {
        foreach ([AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $status) {
            Appointment::factory()->at(self::DAY.' 09:00')->status($status)->create([
                'branch_id' => $this->branch->id,
                'staff_profile_id' => $this->stylist->id,
            ]);
        }

        $this->assertContains('09:00', $this->availableTimes());
    }

    public function test_any_staff_shows_slot_while_one_stylist_is_free(): void
    {
        $second = $this->makeStylist();
        Appointment::factory()->at(self::DAY.' 09:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);

        $this->assertContains('09:00', $this->availableTimes());
        $this->assertNotContains('09:00', $this->availableTimes(['staff_id' => $this->stylist->id]));
        $this->assertContains('09:00', $this->availableTimes(['staff_id' => $second->id]));
    }

    public function test_staff_busy_at_another_branch_is_blocked(): void
    {
        $other = Branch::factory()->withHours()->create();
        Appointment::factory()->at(self::DAY.' 09:00')->create(['branch_id' => $other->id, 'staff_profile_id' => $this->stylist->id]);

        $this->assertNotContains('09:00', $this->availableTimes());
    }

    public function test_branch_closed_weekday(): void
    {
        $this->branch->businessHours()->where('day_of_week', 5)->update(['is_open' => false]); // Friday

        $this->getJson('/api/v1/availability?'.http_build_query(['branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => self::DAY]))
            ->assertOk()->assertJsonPath('data.is_open', false)->assertJsonPath('data.slots', []);
    }

    public function test_branch_closure_date(): void
    {
        BranchClosure::create(['branch_id' => $this->branch->id, 'date' => self::DAY, 'reason' => 'Holiday']);

        $this->getJson('/api/v1/availability?'.http_build_query(['branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => self::DAY]))
            ->assertOk()->assertJsonPath('data.is_open', false)->assertJsonPath('data.closed_reason', 'Holiday');
    }

    public function test_staff_day_off_and_non_working_day(): void
    {
        StaffDayOff::create(['staff_profile_id' => $this->stylist->id, 'date' => self::DAY]);
        $this->assertSame([], $this->availableTimes());

        StaffDayOff::query()->delete();
        $this->stylist->schedules()->where('day_of_week', 5)->update(['is_working' => false]);
        $this->assertSame([], $this->availableTimes());
    }

    public function test_staff_who_does_not_offer_service_is_ignored(): void
    {
        $other = Service::factory()->atBranch($this->branch)->create();

        $this->getJson('/api/v1/availability?'.http_build_query(['branch_id' => $this->branch->id, 'service_id' => $other->id, 'date' => self::DAY]))
            ->assertOk()->assertJsonPath('data.closed_reason', 'No staff available on this date.');
    }

    public function test_minimum_notice_hides_near_slots_today(): void
    {
        $this->travelTo(now()->setTime(9, 10)); // today, 2 h notice → earliest 11:10
        $times = $this->availableTimes(['date' => now()->toDateString()]);

        $this->assertNotContains('10:30', $times);
        $this->assertNotContains('11:00', $times);
        $this->assertContains('13:00', $times);
    }

    public function test_past_date_and_beyond_window_are_closed(): void
    {
        $query = ['branch_id' => $this->branch->id, 'service_id' => $this->service->id];

        $this->getJson('/api/v1/availability?'.http_build_query($query + ['date' => '2026-09-30']))
            ->assertOk()->assertJsonPath('data.is_open', false);

        app(SettingsService::class)->update(['booking_maximum_days' => 7]);
        $this->getJson('/api/v1/availability?'.http_build_query($query + ['date' => '2026-10-20']))
            ->assertOk()->assertJsonPath('data.closed_reason', 'This date is beyond the booking window.');
    }

    public function test_variant_duration_is_used(): void
    {
        $variant = $this->service->variants()->create(['name' => 'Long Hair', 'price' => 1500, 'duration_minutes' => 120]);

        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'service_variant_id' => $variant->id, 'date' => self::DAY,
        ]))->assertOk()->assertJsonPath('data.duration_minutes', 120);

        // 120 + 15 must fit before 18:00 → last grid start 15:30.
        $this->assertSame('15:30', collect($response->json('data.slots'))->last()['start_time']);
    }

    public function test_month_calendar(): void
    {
        $this->stylist->schedules()->where('day_of_week', 0)->delete(); // no Sundays

        $response = $this->getJson('/api/v1/availability/dates?'.http_build_query([
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'month' => '2026-10',
        ]))->assertOk()->assertJsonCount(31, 'data.dates');

        $dates = collect($response->json('data.dates'))->pluck('available', 'date');
        $this->assertTrue($dates['2026-10-02']);
        $this->assertFalse($dates['2026-10-04']); // Sunday
        $this->assertFalse($dates['2026-09-30'] ?? false);
    }

    public function test_validation(): void
    {
        $this->getJson('/api/v1/availability')->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['branch_id', 'service_id', 'date']);
    }
}
