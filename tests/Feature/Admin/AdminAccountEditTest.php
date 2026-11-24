<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Admin accounts → a row opens that account's edit page: name, email, role, status, live/test and a new password. */
class AdminAccountEditTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = User::factory()->superAdmin()->create(['name' => 'Boss Bea']);
        $this->ana = User::factory()->admin()->create(['name' => 'Ana Admin', 'email' => 'ana@example.com']);
        $this->actingAs($this->boss);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['name' => 'Ana Admin', 'email' => 'ana@example.com', 'role' => 'admin', 'status' => 'active', 'account_type' => 'live'];
    }

    public function test_each_row_links_to_its_edit_page(): void
    {
        $this->get('/admin-accounts')->assertOk()
            ->assertSee('data-href="'.route('admin-accounts.edit', $this->ana).'"', false);
        $this->get("/admin-accounts/{$this->ana->id}/edit")->assertOk()
            ->assertSee('Ana Admin')->assertSee('ana@example.com')->assertSee('Save changes');
        // Customers are not admin accounts.
        $this->get('/admin-accounts/'.User::factory()->customer()->create()->id.'/edit')->assertNotFound();
    }

    public function test_saving_name_email_role_status_type_and_password(): void
    {
        $token = $this->ana->createToken('admin-app');

        $this->put("/admin-accounts/{$this->ana->id}", $this->form([
            'name' => 'Ana Cruz', 'email' => ' ANA.CRUZ@example.com ', 'role' => 'cashier', 'account_type' => 'test',
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ]))->assertRedirect('/admin-accounts')->assertSessionHas('success');

        $ana = $this->ana->fresh();
        $this->assertSame(['Ana Cruz', 'ana.cruz@example.com', UserRole::Cashier], [$ana->name, $ana->email, $ana->role]);
        $this->assertTrue($ana->is_test);
        $this->assertTrue(Hash::check('newpass123', $ana->password));
        // The new password signs them out of the admin app.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.updated']);
    }

    public function test_blank_password_keeps_it_and_email_must_be_free(): void
    {
        $before = $this->ana->password;
        $this->put("/admin-accounts/{$this->ana->id}", $this->form(['status' => 'inactive']))->assertSessionHas('success');
        $this->assertSame($before, $this->ana->fresh()->password);
        $this->assertSame(UserStatus::Inactive, $this->ana->fresh()->status);

        $this->put("/admin-accounts/{$this->ana->id}", $this->form(['email' => $this->boss->email]))->assertSessionHasErrors('email');
        $this->put("/admin-accounts/{$this->ana->id}", $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
    }

    public function test_your_own_role_status_and_type_stay_locked(): void
    {
        // Own page: name and email change; the locked role, status and type are ignored even if sent.
        $this->get("/admin-accounts/{$this->boss->id}/edit")->assertOk()->assertSee("You can't change your own role", false);
        $this->put("/admin-accounts/{$this->boss->id}", [
            'name' => 'Boss Beatrice', 'email' => $this->boss->email, 'role' => 'cashier', 'status' => 'suspended', 'account_type' => 'test',
        ])->assertSessionHas('success');
        $boss = $this->boss->fresh();
        $this->assertSame(['Boss Beatrice', UserRole::SuperAdmin, UserStatus::Active, false], [$boss->name, $boss->role, $boss->status, $boss->is_test]);

        // Another super admin can change the boss's role (there is still an active super admin: the one saving).
        $this->actingAs(User::factory()->superAdmin()->create());
        $this->put("/admin-accounts/{$this->boss->id}", $this->form(['name' => 'Boss Beatrice', 'email' => $this->boss->email]))
            ->assertSessionHas('success');
        $this->assertSame(UserRole::Admin, $this->boss->fresh()->role);
    }

    public function test_only_live_super_admins(): void
    {
        $this->actingAs($this->ana);
        $this->get("/admin-accounts/{$this->boss->id}/edit")->assertForbidden();
        $this->put("/admin-accounts/{$this->boss->id}", $this->form())->assertForbidden();
    }
}
