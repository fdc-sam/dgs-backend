<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\ResetPasswordCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_replace([
            'name' => 'John Doe',
            'email' => 'John@Example.com',
            'phone' => '+63 917 123 4567',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'Pixel 9',
        ], $overrides));
    }

    public function test_register_creates_customer_and_returns_token(): void
    {
        $response = $this->register()->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'john@example.com')
            ->assertJsonPath('data.user.phone', '09171234567')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonMissingPath('data.user.password');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull(User::where('email', 'john@example.com')->first()->customerProfile);

        $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.name', 'John Doe');
    }

    public function test_register_validation(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'phone' => '09170000001']);

        $this->register(['email' => 'taken@example.com', 'phone' => '09170000001', 'password' => 'short', 'password_confirmation' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email', 'phone', 'password']);

        $this->register(['phone' => '12345'])->assertJsonValidationErrors('phone');
    }

    public function test_login_success_and_failure(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['success', 'data' => ['user' => ['id', 'email'], 'token', 'token_type']]);

        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_suspended_and_staff_accounts_cannot_use_mobile_api(): void
    {
        User::factory()->suspended()->create(['email' => 's@example.com', 'password' => 'secret123']);
        User::factory()->staff()->create(['email' => 'staff@example.com', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 's@example.com', 'password' => 'secret123'])->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertForbidden();
    }

    public function test_suspended_customer_token_stops_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_logout_revokes_token_and_device(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $user->deviceTokens()->create(['token' => str_repeat('a', 40), 'platform' => 'android']);

        $this->withToken($token)->postJson('/api/v1/auth/logout', ['device_token' => str_repeat('a', 40)])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->deviceTokens()->count());
    }

    public function test_update_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/profile', [
            'name' => 'New Name',
            'phone' => '09181234567',
            'date_of_birth' => '1995-04-01',
            'gender' => 'female',
            'address' => 'Somewhere',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.profile.date_of_birth', '1995-04-01')
            ->assertJsonPath('data.profile.gender', 'female');

        $this->putJson('/api/v1/auth/profile', ['gender' => 'robot'])->assertStatus(422);
    }

    public function test_change_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', ['current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertOk();

        $this->assertTrue(\Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_forgot_and_reset_password_with_code(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk(); // no enumeration
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();

        $code = null;
        Notification::assertSentTo($user, ResetPasswordCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $code === '000000' ? '111111' : '000000', 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123'])
            ->assertStatus(422)->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $code, 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123'])
            ->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'brandnew123'])->assertOk();
    }

    public function test_reset_code_is_invalidated_after_too_many_attempts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com']);
        $code = null;
        Notification::assertSentTo($user, ResetPasswordCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $wrong = $code === '999999' ? '999998' : '999999';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $wrong, 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123']);
        }

        // Even the right code no longer works.
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $code, 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123'])
            ->assertStatus(422);
    }

    public function test_a_new_reset_code_does_not_buy_new_guesses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $reset = fn (string $token) => $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $token, 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $reset('abcdef')->assertStatus(422); // never a valid 6-digit code
        }

        // Asking for a fresh code does not reset the counter: even the right new code is refused for the hour.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();
        $reset($this->lastResetCode($user))->assertStatus(422)->assertJsonPath('errors.token.0', 'Too many incorrect attempts. Please wait an hour, then request a new reset code.');

        $this->travel(61)->minutes();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();
        $reset($this->lastResetCode($user))->assertOk();
    }

    public function test_reset_codes_are_limited_to_three_an_hour(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk(); // same answer every time
        }

        Notification::assertSentToTimes($user, ResetPasswordCode::class, 3);
    }

    public function test_changing_the_password_signs_out_other_devices_but_keeps_this_one(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $thisPhone = $user->createToken('this phone')->plainTextToken;
        $user->createToken('lost phone');
        $user->deviceTokens()->create(['token' => 'push-this', 'platform' => 'android']);
        $user->deviceTokens()->create(['token' => 'push-lost', 'platform' => 'android']);

        $this->withToken($thisPhone)->putJson('/api/v1/auth/password', [
            'current_password' => 'secret123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123', 'device_token' => 'push-this',
        ])->assertOk();

        $this->assertSame(['this phone'], $user->tokens()->pluck('name')->all());
        $this->assertSame(['push-this'], $user->deviceTokens()->pluck('token')->all());
    }

    public function test_a_password_reset_stops_push_to_every_device(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('lost phone');
        $user->deviceTokens()->create(['token' => 'push-lost', 'platform' => 'android']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com']);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $this->lastResetCode($user), 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, $user->deviceTokens()->count());
    }

    private function lastResetCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, ResetPasswordCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_protected_routes_require_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
