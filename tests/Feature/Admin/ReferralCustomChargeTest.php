<?php

namespace Tests\Feature\Admin;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** A referral whose only line is a custom charge (no catalog service) can still be completed. */
class ReferralCustomChargeTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    public function test_a_custom_charge_is_enough_to_complete_a_referral(): void
    {
        $this->setUpSalon();
        Notification::fake();
        $this->actingAs(User::factory()->admin()->create());
        $referrer = $this->customer(['name' => 'Diego Cruz']);
        $referral = Referral::create(['referrer_id' => $referrer->id, 'name' => 'Rtt', 'phone' => '09772555831', 'status' => ReferralStatus::InProgress]);

        // Nothing added yet: no Complete button, and completing is refused.
        $this->get("/referrals/{$referral->id}")->assertOk()
            ->assertSee('Add at least one service or charge before completing this referral.')->assertDontSee("\$dispatch('open-modal', 'referral-complete')", false);
        $this->post("/referrals/{$referral->id}/complete", ['amount' => 100])->assertSessionHasErrors('services');

        $this->post("/referrals/{$referral->id}/items", ['item_type' => 'custom', 'service_name' => 'long', 'unit_price' => 2000, 'quantity' => 1])
            ->assertSessionHasNoErrors();
        $this->get("/referrals/{$referral->id}")->assertOk()->assertSee("\$dispatch('open-modal', 'referral-complete')", false);

        $this->post("/referrals/{$referral->id}/complete", ['amount' => 100])->assertSessionHasNoErrors();
        $this->assertSame(ReferralStatus::Completed, $referral->fresh()->status);
    }
}
