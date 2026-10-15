<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_view_another_customers_appointment(): void
    {
        $appointment = Appointment::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_customer_appointment_list_is_scoped_to_self(): void
    {
        Appointment::factory()->count(2)->create();
        $mine = Appointment::factory()->create();
        Sanctum::actingAs($mine->customer);

        $this->getJson('/api/v1/appointments')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_customer_payload_hides_admin_fields(): void
    {
        $appointment = Appointment::factory()->create(['admin_notes' => 'VIP, prefers quiet']);
        Sanctum::actingAs($appointment->customer);

        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertOk()
            ->assertJsonMissingPath('data.admin_notes')
            ->assertJsonMissingPath('data.customer');
    }

    public function test_staff_and_admin_tokens_are_rejected_by_customer_api(): void
    {
        foreach (['staff', 'admin', 'superAdmin'] as $state) {
            Sanctum::actingAs(User::factory()->{$state}()->create());
            $this->getJson('/api/v1/appointments')->assertForbidden();
        }
    }

    public function test_user_payload_never_exposes_secrets(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $json = $this->getJson('/api/v1/auth/me')->assertOk()->getContent();
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
    }

    public function test_review_policy_hides_pending_reviews_from_others(): void
    {
        $review = Review::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/reviews/{$review->id}")->assertNotFound();
    }

    public function test_rate_limit_on_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_security_headers(): void
    {
        $this->getJson('/api/v1/branches')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
