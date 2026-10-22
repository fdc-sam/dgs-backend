<?php

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_guest_is_redirected_to_login_and_login_page_renders(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_the_logo_and_icon_urls_change_when_the_files_do(): void
    {
        // A replaced logo keeps its file name; the version stops browsers showing the copy they cached.
        $version = filemtime(public_path('images/dgs-logo-256.png'));
        $this->get('/login')->assertOk()
            ->assertSee(asset('images/dgs-logo-256.png').'?v='.$version, false)
            ->assertSee(asset('favicon.png').'?v='.filemtime(public_path('favicon.png')), false);
    }

    public function test_lists_show_ten_rows_per_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        User::factory()->customer()->count(11)->create();
        User::factory()->admin()->count(10)->create();

        $this->get('/customers')->assertOk()->assertViewHas('customers', fn ($page) => $page->perPage() === 10 && $page->count() === 10 && $page->hasMorePages())
            ->assertSee('Showing');
        $this->get('/admin-accounts')->assertOk()->assertViewHas('admins', fn ($page) => $page->perPage() === 10 && $page->hasMorePages());
        foreach (['/appointments' => 'appointments', '/services' => 'services', '/categories' => 'categories', '/products' => 'products',
            '/promotions' => 'promotions', '/achievements' => 'achievements', '/walk-ins' => 'visits', '/payments' => 'appointments',
            '/reviews' => 'reviews', '/notifications' => 'notifications'] as $url => $list) {
            $this->get($url)->assertOk()->assertViewHas($list, fn ($page) => $page->perPage() === 10);
        }
    }

    public function test_admin_login_and_logout(): void
    {
        User::factory()->admin()->create(['email' => 'boss@example.com', 'password' => 'secret123']);

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'BOSS@example.com', 'password' => 'secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_customers_cannot_sign_in_to_admin(): void
    {
        User::factory()->create(['email' => 'c@example.com', 'password' => 'secret123']);

        $this->post('/login', ['email' => 'c@example.com', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_every_admin_page_renders_for_super_admin(): void
    {
        $appointment = Appointment::factory()->at(self::DAY.' 10:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);
        Appointment::factory()->at('2026-09-25 10:00')->completed()->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id, 'total_amount' => 800]);
        $promotion = Promotion::factory()->create();
        Review::factory()->create();
        $category = ServiceCategory::factory()->create();
        $product = Product::create(['name' => 'Shampoo', 'unit' => 'ml', 'unit_cost' => 0.4]);
        $this->service->products()->attach($product, ['quantity' => 30]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $pages = [
            '/dashboard', '/calendar', '/calendar?view=day', '/calendar?view=month', '/appointments', '/appointments?status=pending&customer=x',
            "/appointments/{$appointment->id}", '/services', '/services/create', "/services/{$this->service->id}/edit",
            '/categories', '/categories/create', "/categories/{$category->id}/edit", '/products', '/products/create', "/products/{$product->id}/edit", '/promotions', '/promotions/create',
            "/promotions/{$promotion->id}/edit", "/promotions/{$promotion->id}/preview", '/customers', "/customers/{$appointment->customer_id}",
            '/staff', '/staff/create', "/staff/{$this->stylist->id}/edit", '/branches', '/branches/create', "/branches/{$this->branch->id}/edit",
            '/referrals', '/referrals?status=all&search=x',
            '/reviews', '/reviews?status=all', '/reports', '/reports/sales', '/reports/appointments', '/reports/services', '/reports/staff',
            '/reports/branches', '/reports/sales?range=custom&from=2026-09-01&to=2026-09-30', '/notifications', '/settings', '/account',
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }

    /** Every audited model needs a morph map alias, or the audit log write turns the save into a 500. */
    public function test_categories_can_be_created_edited_and_deleted(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post('/categories', ['name' => 'Hair Spa', 'slug' => '', 'sort_order' => '0', 'description' => '', 'status' => 'active'])
            ->assertRedirect(route('categories.index'));
        $category = ServiceCategory::where('slug', 'hair-spa')->firstOrFail();

        $this->put("/categories/{$category->id}", ['name' => 'Hair Treatments', 'slug' => 'hair-spa', 'status' => 'inactive'])
            ->assertRedirect(route('categories.index'));
        $this->assertSame('Hair Treatments', $category->fresh()->name);

        $this->delete("/categories/{$category->id}")->assertRedirect(route('categories.index'));
        $this->assertSoftDeleted($category);

        $this->assertEqualsCanonicalizing(['category.created', 'category.updated', 'category.deleted'],
            AuditLog::where('entity_type', 'service_category')->where('entity_id', $category->id)->pluck('action')->all());
    }

    public function test_admin_cannot_open_settings_and_staff_is_limited(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/settings')->assertForbidden();
        $this->get('/services')->assertOk();

        $this->actingAs($this->stylist->user);
        foreach (['/services', '/products', '/customers', '/reports', '/staff', '/branches', '/promotions', '/reviews', '/settings'] as $page) {
            $this->get($page)->assertForbidden();
        }
        foreach (['/dashboard', '/calendar', '/appointments', '/my-schedule', '/notifications', '/account'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_staff_only_sees_own_appointments(): void
    {
        $other = $this->makeStylist();
        $mine = Appointment::factory()->at(self::DAY.' 10:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $theirs = Appointment::factory()->at(self::DAY.' 11:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $other->id]);

        $this->actingAs($this->stylist->user);
        $this->get('/appointments')->assertSee($mine->appointment_number)->assertDontSee($theirs->appointment_number);
        $this->get("/appointments/{$theirs->id}")->assertForbidden();
        $this->post("/appointments/{$mine->id}/status", ['status' => 'completed'])->assertRedirect();
        $this->post("/appointments/{$theirs->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->post("/appointments/{$mine->id}/cancel")->assertForbidden(); // only managers/customer cancel
    }

    public function test_report_exports(): void
    {
        Appointment::factory()->at('2026-09-25 10:00')->completed()->create(['branch_id' => $this->branch->id, 'total_amount' => 800]);
        $this->actingAs(User::factory()->admin()->create());

        foreach (['sales', 'appointments', 'services', 'staff', 'branches'] as $report) {
            $csv = $this->get("/reports/{$report}?range=custom&from=2026-09-01&to=2026-09-30&export=csv")->assertOk()->assertDownload();
            $this->assertStringContainsString('csv', $csv->headers->get('content-type'));
            $this->get("/reports/{$report}?range=month&export=xlsx")->assertOk()->assertDownload();
            $this->get("/reports/{$report}?range=month&export=pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        }

        $sales = $this->get('/reports/sales?range=custom&from=2026-09-01&to=2026-09-30&export=csv')->streamedContent();
        $this->assertStringContainsString('800', $sales);
    }
}
