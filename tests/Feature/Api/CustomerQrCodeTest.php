<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\CustomerQrService;
use App\Support\CustomerCode;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class CustomerQrCodeTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private const FORMAT = '/^DGS-[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){4}$/';

    public function test_a_customer_gets_a_unique_code_the_moment_they_are_created(): void
    {
        $customer = User::factory()->customer()->create();

        $this->assertMatchesRegularExpression(self::FORMAT, $customer->qr_code);
        $this->assertSame($customer->qr_code, $customer->fresh()->qr_code);
    }

    public function test_registering_in_the_app_creates_the_code_in_the_same_request(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Maria Santos', 'email' => 'maria@example.com', 'phone' => '09171234567',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->assertMatchesRegularExpression(self::FORMAT, User::where('email', 'maria@example.com')->value('qr_code'));
    }

    public function test_every_customer_has_a_different_code(): void
    {
        $codes = User::factory()->customer()->count(40)->create()->pluck('qr_code');

        $this->assertCount(40, $codes->unique());
    }

    public function test_the_code_cannot_be_set_through_mass_assignment(): void
    {
        // Laravel refuses outright (rather than silently ignoring) a field that is not in the model's fillable list.
        $this->expectException(MassAssignmentException::class);
        User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'qr_code' => 'DGS-HACK-HACK-HACK-HACK-HACK']);
    }

    public function test_staff_and_admins_do_not_get_a_customer_code(): void
    {
        $this->assertNull(User::factory()->admin()->create()->qr_code);
        $this->assertNull(User::factory()->superAdmin()->create()->qr_code);
    }

    public function test_the_database_refuses_a_duplicate_code(): void
    {
        $first = User::factory()->customer()->create();

        $this->expectException(QueryException::class);
        User::factory()->customer()->create()->forceFill(['qr_code' => $first->qr_code])->save();
    }

    public function test_code_normalising_accepts_scanner_and_typed_input(): void
    {
        $code = CustomerCode::generate();

        $this->assertSame($code, CustomerCode::normalize($code));
        $this->assertSame($code, CustomerCode::normalize(strtolower($code)));
        $this->assertSame($code, CustomerCode::normalize(str_replace('-', ' ', $code)));
        $this->assertSame($code, CustomerCode::normalize("  {$code}\n"));
        $this->assertNull(CustomerCode::normalize('DGS-1234'));
        $this->assertNull(CustomerCode::normalize('ABC-AAAA-AAAA-AAAA-AAAA-AAAA'));
        $this->assertNull(CustomerCode::normalize('DGS-AAAA-AAAA-AAAA-AAAA-AAA0')); // 0 is not in the alphabet
    }

    public function test_the_app_gets_its_own_qr_png(): void
    {
        $customer = $this->actingAsCustomer();

        $response = $this->get('/api/v1/auth/qr')->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        $png = $response->getContent();
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        [$w, $h] = getimagesizefromstring($png);
        $this->assertGreaterThan(CustomerQrService::MIN_SIZE, $w);
        $this->assertGreaterThan($w, $h); // taller than wide: the code is printed under the QR

        // The round logo's clear corners stay white (the PNG has no alpha, so they used to turn black).
        $image = imagecreatefromstring($png);
        $logoStart = intdiv($w - (int) round(CustomerQrService::DEFAULT_SIZE * 0.22), 2);
        $corner = imagecolorsforindex($image, imagecolorat($image, $logoStart + 2, $logoStart + 2));
        $this->assertSame([255, 255, 255], [$corner['red'], $corner['green'], $corner['blue']]);

        // Same customer, same picture; a different customer, a different picture.
        $this->assertSame($png, $this->get('/api/v1/auth/qr')->getContent());
        Sanctum::actingAs($this->customer());
        $this->assertNotSame($png, $this->get('/api/v1/auth/qr')->getContent());
        $this->assertNotNull($customer->qr_code);
    }

    public function test_the_png_supports_a_size_and_etag_revalidation(): void
    {
        $this->actingAsCustomer();

        [$small] = getimagesizefromstring($this->get('/api/v1/auth/qr?size=300')->getContent());
        [$large] = getimagesizefromstring($this->get('/api/v1/auth/qr?size=900')->getContent());
        $this->assertLessThan($large, $small);

        $etag = $this->get('/api/v1/auth/qr')->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->get('/api/v1/auth/qr', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_the_qr_endpoint_needs_a_signed_in_customer(): void
    {
        $this->getJson('/api/v1/auth/qr')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/auth/qr')->assertForbidden();
    }

    public function test_the_code_is_not_exposed_in_the_profile_payload(): void
    {
        $customer = $this->actingAsCustomer();

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonMissingPath('data.qr_code');
        $this->assertStringNotContainsString($customer->qr_code, json_encode($this->getJson('/api/v1/auth/me')->json()));
    }

    public function test_admin_sees_the_qr_on_the_customer_page_and_can_download_it(): void
    {
        $this->setUpSalon();
        $customer = $this->customer();
        $this->actingAs(User::factory()->admin()->create());

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee($customer->qr_code)->assertSee("/customers/{$customer->id}/qr", false);
        $this->get("/customers/{$customer->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/customers/{$customer->id}/qr?download=1")->assertOk()->assertDownload();
    }

    public function test_admin_finds_a_customer_by_scanned_code(): void
    {
        $this->setUpSalon();
        $target = $this->customer(['name' => 'Target Person']);
        $other = $this->customer(['name' => 'Someone Else']);
        $this->actingAs(User::factory()->admin()->create());

        foreach ([$target->qr_code, strtolower($target->qr_code), str_replace('-', '', $target->qr_code)] as $scanned) {
            $this->get('/customers?search='.urlencode($scanned))->assertOk()->assertSee('Target Person')->assertDontSee('Someone Else');
        }
    }

    public function test_only_the_admin_portal_can_open_a_customers_qr_by_url(): void
    {
        $this->setUpSalon();
        $customer = $this->customer();

        $this->get("/customers/{$customer->id}/qr")->assertRedirect('/login');

        // Another customer who guesses the URL is turned away from the admin portal and gets no image.
        $this->actingAs($this->customer());
        $response = $this->get("/customers/{$customer->id}/qr");
        $response->assertRedirect();
        $this->assertNotSame('image/png', $response->headers->get('Content-Type'));
    }
}
