<?php

namespace Tests\Feature\Admin;

use App\Models\Referral;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** Settings → Loyalty card & referrals: the card perks and referral rewards, shown in the app and used to pre-fill rewards. */
class LoyaltyProgramTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    /** The required settings fields, unchanged. */
    private function requiredSettings(): array
    {
        return array_intersect_key(app(SettingsService::class)->all(), array_flip(['salon_name', 'currency', 'currency_symbol',
            'booking_minimum_notice_hours', 'booking_maximum_days', 'booking_slot_interval_minutes', 'cancellation_hours', 'payment_window_minutes']));
    }

    /** The settings form as the page submits it, with only the loyalty fields changed. */
    private function saveSettings(array $loyalty): TestResponse
    {
        return $this->put('/settings', [...$this->requiredSettings(), 'referral_rewards_shown' => '1', ...$loyalty]);
    }

    public function test_the_app_gets_the_perks_and_rewards_printed_on_the_card_by_default(): void
    {
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.loyalty_card_perks.0', 'Haircut 50% discount for men')
            ->assertJsonPath('data.loyalty_card_perks.1', 'Less ₱200 for rebond')
            ->assertJsonCount(4, 'data.loyalty_card_perks')
            ->assertJsonPath('data.referral_rewards', [
                ['service' => 'Haircut', 'amount' => 20], ['service' => 'Rebond', 'amount' => 200],
                ['service' => 'Manicure', 'amount' => 50], ['service' => 'Brazilian botox', 'amount' => 200],
            ]);
    }

    public function test_the_owner_edits_the_perks_and_rewards_in_settings(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get('/settings')->assertOk()->assertSee('Loyalty card &amp; referrals', false)->assertSee('Less ₱200 for rebond');

        $this->saveSettings([
            'loyalty_card_perks' => "- Free hair spa on your birthday\n\n• 10% off every 5th visit ",
            'referral_rewards' => [
                ['service' => ' Hair color ', 'amount' => '150'],
                ['service' => '', 'amount' => ''], // an empty row the owner added and left blank
                ['service' => 'Pedicure', 'amount' => '40.5'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.loyalty_card_perks', ['Free hair spa on your birthday', '10% off every 5th visit'])
            ->assertJsonPath('data.referral_rewards', [['service' => 'Hair color', 'amount' => 150], ['service' => 'Pedicure', 'amount' => 40.5]]);

        // Removing every row clears the rewards; a form without the section leaves them alone.
        $this->saveSettings(['loyalty_card_perks' => ''])->assertSessionHasNoErrors();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.referral_rewards', [])->assertJsonPath('data.loyalty_card_perks', []);
        $this->saveSettings(['referral_rewards' => [['service' => 'Haircut', 'amount' => 25]]]);
        $this->put('/settings', $this->requiredSettings())->assertSessionHasNoErrors();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.referral_rewards', [['service' => 'Haircut', 'amount' => 25]]);
    }

    public function test_reward_rows_need_a_service_and_an_amount_once_each(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->saveSettings(['referral_rewards' => [['service' => 'Haircut', 'amount' => '']]])
            ->assertSessionHasErrors(['referral_rewards.0.amount' => 'Enter the reward amount.']);
        $this->saveSettings(['referral_rewards' => [['service' => '', 'amount' => '20']]])
            ->assertSessionHasErrors(['referral_rewards.0.service' => 'Enter the service this reward is for.']);
        $this->saveSettings(['referral_rewards' => [['service' => 'Haircut', 'amount' => '20'], ['service' => 'HAIRCUT', 'amount' => '30']]])
            ->assertSessionHasErrors('referral_rewards.1.service');
    }

    public function test_completing_a_referral_starts_with_the_rewards_for_its_services(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $nails = ServiceCategory::factory()->create(['name' => 'Manicure & Pedicure']);
        $haircut = Service::factory()->atBranch($this->branch)->create(['name' => "Men's Hair Cut"]);
        $rebond = Service::factory()->atBranch($this->branch)->create(['name' => 'Hair Rebonding']);
        $gel = Service::factory()->atBranch($this->branch)->create(['name' => 'Gel Polish', 'category_id' => $nails->id]);
        $kids = Service::factory()->atBranch($this->branch)->create(['name' => 'Kids Haircut']);

        $both = Referral::create(['referrer_id' => $customer->id, 'name' => 'Maria', 'phone' => '09175550101']);
        $both->services()->attach([$haircut->id, $rebond->id, $kids->id]); // two haircuts still earn the haircut reward once
        $byCategory = Referral::create(['referrer_id' => $customer->id, 'name' => 'Joy', 'phone' => '09175550102']);
        $byCategory->services()->attach($gel->id);
        $none = Referral::create(['referrer_id' => $customer->id, 'name' => 'Lito', 'phone' => '09175550103']);
        $none->services()->attach($this->service->id);

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/referrals')->assertOk()
            ->assertViewHas('suggestions', fn ($s) => $s[$both->id] == ['amount' => 220.0, 'summary' => 'Haircut ₱20.00 + Rebond ₱200.00']
                && $s[$byCategory->id] == ['amount' => 50.0, 'summary' => 'Manicure ₱50.00']
                && $s[$none->id] === null)
            ->assertSee('Filled in from your referral rewards')
            ->assertDontSee(route('settings.edit').'#loyalty'); // only super admins can open Settings
    }

    public function test_the_customer_page_shows_the_card_like_the_printed_one(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee('No loyalty card linked')->assertSee('LC-•••••••');

        $customer->forceFill(['loyalty_number' => '0000353'])->save();
        $this->get("/customers/{$customer->id}")->assertOk()
            ->assertSee('aria-label="Loyalty card LC-0000353, Ana Reyes"', false)
            ->assertSee('Customer name')->assertDontSee('No loyalty card linked yet.');
    }
}
