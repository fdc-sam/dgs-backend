<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/**
 * Standard fixture: one branch open 09:00–18:00 every day, a 60-minute service with a 15-minute
 * buffer, and one stylist working 09:00–18:00 with a 12:00–13:00 break.
 * "Now" is frozen at Thursday 2026-10-01 08:00 (Asia/Manila); bookings target Friday 2026-10-02.
 */
trait BuildsSalon
{
    protected Branch $branch;

    protected Service $service;

    protected StaffProfile $stylist;

    protected const DAY = '2026-10-02';

    protected function setUpSalon(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00'));

        $this->branch = Branch::factory()->withHours('09:00', '18:00')->create();
        $this->service = Service::factory()->duration(60, 15)->atBranch($this->branch)->create(['regular_price' => 1000]);
        $this->stylist = $this->makeStylist();
    }

    protected function makeStylist(array $services = [], ?Branch $branch = null): StaffProfile
    {
        return StaffProfile::factory()
            ->worksAt($branch ?? $this->branch)
            ->offers($services ?: [$this->service])
            ->create();
    }

    protected function customer(array $attributes = []): User
    {
        return User::factory()->customer()->create($attributes);
    }

    protected function actingAsCustomer(?User $user = null): User
    {
        $user ??= $this->customer();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function bookingPayload(array $overrides = []): array
    {
        return array_replace([
            'branch_id' => $this->branch->id,
            'staff_id' => $this->stylist->id,
            'appointment_date' => self::DAY,
            'start_time' => '09:00',
            'services' => [['service_id' => $this->service->id, 'service_variant_id' => null]],
            'customer_notes' => null,
        ], $overrides);
    }

    /** @return list<string> available start times */
    protected function availableTimes(array $query = []): array
    {
        $response = $this->getJson('/api/v1/availability?'.http_build_query(array_replace([
            'branch_id' => $this->branch->id,
            'service_id' => $this->service->id,
            'date' => self::DAY,
        ], $query)))->assertOk();

        return collect($response->json('data.slots'))->where('available', true)->pluck('start_time')->values()->all();
    }
}
