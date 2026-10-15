<?php

namespace Tests\Feature\Api;

use App\Models\Promotion;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_list_and_remove_each_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $service = Service::factory()->create();
        $staff = StaffProfile::factory()->create();
        $promo = Promotion::factory()->create();

        $favoriteId = $this->postJson('/api/v1/favorites', ['type' => 'service', 'id' => $service->id])
            ->assertCreated()->assertJsonPath('data.type', 'service')->assertJsonPath('data.item.id', $service->id)
            ->json('data.id');
        $this->postJson('/api/v1/favorites', ['type' => 'service', 'id' => $service->id])->assertOk(); // idempotent
        $this->postJson('/api/v1/favorites', ['type' => 'staff', 'id' => $staff->id])->assertCreated();
        $this->postJson('/api/v1/favorites', ['type' => 'promotion', 'id' => $promo->id])->assertCreated();

        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/favorites?type=staff')->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.name', $staff->user->name);
        $this->getJson("/api/v1/services/{$service->id}")->assertJsonPath('data.is_favorite', true);

        $this->deleteJson("/api/v1/favorites/{$favoriteId}")->assertOk();
        $this->getJson('/api/v1/favorites?type=service')->assertJsonCount(0, 'data');
    }

    public function test_invalid_target(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/favorites', ['type' => 'branch', 'id' => str_repeat('a', 26)])->assertStatus(422);
        $this->postJson('/api/v1/favorites', ['type' => 'service', 'id' => Service::factory()->inactive()->create()->id])->assertNotFound();
        $this->postJson('/api/v1/favorites', ['type' => 'promotion', 'id' => Promotion::factory()->draft()->create()->id])->assertNotFound();
    }

    public function test_cannot_delete_others_favorite(): void
    {
        $owner = User::factory()->create();
        $favorite = $owner->favorites()->create(['favoritable_type' => 'service', 'favoritable_id' => Service::factory()->create()->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/favorites/{$favorite->id}")->assertForbidden();
        $this->assertModelExists($favorite);
    }
}
