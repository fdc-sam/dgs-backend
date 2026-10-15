<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_currently_active_promotions_are_listed(): void
    {
        $active = Promotion::factory()->create();
        Promotion::factory()->draft()->create();
        Promotion::factory()->expired()->create();
        Promotion::factory()->upcoming()->create();

        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.discount_label', '10% off')
            ->assertJsonPath('data.0.is_active', true);
        $this->getJson('/api/v1/promotions/active')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_branch_filter_includes_all_branch_promotions(): void
    {
        $branch = Branch::factory()->create();
        $global = Promotion::factory()->create();
        $local = Promotion::factory()->create();
        $local->branches()->attach($branch->id);
        $elsewhere = Promotion::factory()->create();
        $elsewhere->branches()->attach(Branch::factory()->create()->id);

        $ids = collect($this->getJson("/api/v1/promotions?branch_id={$branch->id}")->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$global->id, $local->id], $ids->all());
    }

    public function test_draft_is_hidden_but_expired_detail_is_viewable(): void
    {
        $this->getJson('/api/v1/promotions/'.Promotion::factory()->draft()->create()->id)->assertNotFound();
        $this->getJson('/api/v1/promotions/'.Promotion::factory()->expired()->create()->id)
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_discount_labels(): void
    {
        $this->getJson('/api/v1/promotions/'.Promotion::factory()->specialPrice(1999)->create()->id)
            ->assertJsonPath('data.discount_label', 'Now ₱1,999.00');
        $this->getJson('/api/v1/promotions/'.Promotion::factory()->fixed(100)->create()->id)
            ->assertJsonPath('data.discount_label', '₱100.00 off');
    }
}
