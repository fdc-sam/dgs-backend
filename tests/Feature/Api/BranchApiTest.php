<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_branches_with_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00')); // Thursday
        Branch::factory()->withHours('09:00', '18:00', [0])->create(['name' => 'A Branch']);
        Branch::factory()->inactive()->create();

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_open_now', true)
            ->assertJsonPath('data.0.business_hours.0.is_open', false)
            ->assertJsonPath('data.0.business_hours.1.opening_time', '09:00')
            ->assertJsonPath('data.0.business_hours.1.day_name', 'Monday');
    }

    public function test_branch_detail_services_and_staff(): void
    {
        $branch = Branch::factory()->withHours()->create();
        $service = Service::factory()->atBranch($branch, 499)->create();
        Service::factory()->create(); // not at branch
        $staff = StaffProfile::factory()->worksAt($branch)->offers($service)->create();
        StaffProfile::factory()->inactive()->worksAt($branch)->create();

        $this->getJson("/api/v1/branches/{$branch->id}")->assertOk()->assertJsonPath('data.id', $branch->id);
        $this->getJson("/api/v1/branches/{$branch->id}/services")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', 499);
        $this->getJson("/api/v1/branches/{$branch->id}/staff")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $staff->id);
        $this->getJson("/api/v1/branches/{$branch->id}/staff?service_id={$service->id}")->assertJsonCount(1, 'data');
    }

    public function test_inactive_branch_is_404(): void
    {
        $this->getJson('/api/v1/branches/'.Branch::factory()->inactive()->create()->id)->assertNotFound();
    }

    public function test_staff_endpoints(): void
    {
        $branch = Branch::factory()->create();
        $service = Service::factory()->atBranch($branch)->create();
        $staff = StaffProfile::factory()->worksAt($branch)->offers($service)->create();
        StaffProfile::factory()->create();

        $this->getJson("/api/v1/staff?branch_id={$branch->id}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_ids.0', $service->id);
        $this->getJson("/api/v1/staff?service_id={$service->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/staff/{$staff->id}")->assertOk()->assertJsonPath('data.name', $staff->user->name)
            ->assertJsonMissingPath('data.email');
        $this->getJson("/api/v1/staff/{$staff->id}/services")->assertOk()->assertJsonCount(1, 'data');
    }
}
