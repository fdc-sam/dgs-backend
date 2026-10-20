<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** Password changes end other sessions; stylists don't see customer contact details. */
class AccountSecurityTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_a_password_change_elsewhere_signs_this_browser_out(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'oldpass123']);
        // This browser signed in with the old password (the `auth.session` middleware remembers its hash).
        $session = ['password_hash_web' => auth()->guard('web')->hashPasswordForCookie($admin->password)];
        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertOk();

        // The password is then changed from another browser (or reset).
        $admin->update(['password' => 'newpass123']);

        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_changing_the_password_on_the_web_ends_admin_app_sessions(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'oldpass123']);
        $admin->createToken('admin-app', ['admin-app']);
        $admin->deviceTokens()->create(['token' => 'push-a', 'platform' => 'android']);

        $this->actingAs($admin)->put('/account/password', [
            'current_password' => 'oldpass123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('newpass123', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(0, $admin->deviceTokens()->count());
    }

    public function test_an_admin_setting_a_staff_password_ends_the_staff_members_sessions(): void
    {
        $user = $this->stylist->user;
        $user->createToken('admin-app', ['admin-app']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->put("/staff/{$this->stylist->id}", [
            'name' => $user->name, 'email' => $user->email, 'status' => 'active', 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123',
            'branches' => [$this->branch->id], 'services' => [$this->service->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_stylists_do_not_see_customer_contact_details(): void
    {
        $customer = $this->customer(['email' => 'private@example.com', 'phone' => '09170000001']);
        $appointment = app(AppointmentService::class)->book($customer, $this->bookingPayload(['appointment_date' => '2026-10-01', 'start_time' => '10:00']));

        $this->actingAs($this->stylist->user)->get("/appointments/{$appointment->id}")
            ->assertOk()->assertSee($customer->name)->assertDontSee('private@example.com')->assertDontSee('09170000001');
        Sanctum::actingAs($this->stylist->user, ['admin-app']);
        $this->getJson("/api/v1/admin/appointments/{$appointment->id}")
            ->assertOk()->assertJsonPath('data.customer.name', $customer->name)
            ->assertJsonMissingPath('data.customer.email')->assertJsonMissingPath('data.customer.phone');

        Sanctum::actingAs(User::factory()->admin()->create(), ['admin-app']);
        $this->getJson("/api/v1/admin/appointments/{$appointment->id}")
            ->assertOk()->assertJsonPath('data.customer.email', 'private@example.com')->assertJsonPath('data.customer.phone', '09170000001');
    }
}
