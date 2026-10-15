<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_services_paginated(): void
    {
        Service::factory()->count(3)->create();
        Service::factory()->inactive()->create();

        $this->getJson('/api/v1/services?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'price', 'price_from', 'duration_minutes', 'category', 'variants', 'is_favorite', 'rating_average']]]);
    }

    public function test_filters_by_category_search_featured_and_branch(): void
    {
        $category = ServiceCategory::factory()->create();
        $branch = Branch::factory()->create();
        $rebond = Service::factory()->featured()->atBranch($branch, 2222)->create(['category_id' => $category->id, 'name' => 'Hair Rebond']);
        Service::factory()->create(['name' => 'Manicure']);

        $this->getJson("/api/v1/services?category_id={$category->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rebond->id);
        $this->getJson('/api/v1/services?search=rebo')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/services?featured=1')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/services?branch_id={$branch->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', 2222);
        $this->getJson('/api/v1/services/featured')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_wildcards_are_escaped(): void
    {
        Service::factory()->create(['name' => 'Haircut']);

        $this->getJson('/api/v1/services?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_detail_includes_variants_branches_staff_and_rating(): void
    {
        $branch = Branch::factory()->create();
        $service = Service::factory()->atBranch($branch)->create(['regular_price' => 2500]);
        $service->variants()->create(['name' => 'Short', 'price' => 2000, 'duration_minutes' => 120]);
        StaffProfile::factory()->worksAt($branch)->offers($service)->create();
        foreach ([4, 5] as $rating) {
            Review::factory()->approved()->create(['service_id' => $service->id, 'rating' => $rating]);
        }
        Review::factory()->create(['service_id' => $service->id, 'rating' => 1]); // pending: ignored

        $this->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.price_from', 2000)
            ->assertJsonPath('data.variants.0.name', 'Short')
            ->assertJsonPath('data.branches.0.id', $branch->id)
            ->assertJsonCount(1, 'data.staff')
            ->assertJsonPath('data.rating_average', 4.5)
            ->assertJsonPath('data.reviews_count', 2);
    }

    public function test_inactive_or_missing_service_is_404(): void
    {
        $this->getJson('/api/v1/services/'.Service::factory()->inactive()->create()->id)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/services/01aaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
    }

    public function test_is_favorite_reflects_token_user(): void
    {
        $service = Service::factory()->create();
        $user = User::factory()->create();
        $favorite = $user->favorites()->create(['favoritable_type' => 'service', 'favoritable_id' => $service->id]);

        $this->getJson("/api/v1/services/{$service->id}")->assertJsonPath('data.is_favorite', false);

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/services/{$service->id}")
            ->assertJsonPath('data.is_favorite', true)
            ->assertJsonPath('data.favorite_id', $favorite->id);
    }

    public function test_categories(): void
    {
        $category = ServiceCategory::factory()->create();
        Service::factory()->count(2)->create(['category_id' => $category->id]);
        ServiceCategory::factory()->create(['status' => 'inactive']);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.services_count', 2);
        $this->getJson("/api/v1/categories/{$category->id}")->assertOk()->assertJsonCount(2, 'data.services');
    }
}
