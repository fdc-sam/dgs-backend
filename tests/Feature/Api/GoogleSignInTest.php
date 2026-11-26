<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'web-client.apps.googleusercontent.com';

    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_ids' => 'other-client, '.self::CLIENT_ID]);
        // Google's signing certificates, as https://www.googleapis.com/oauth2/v1/certs serves them.
        Http::fake([GoogleIdTokenVerifier::CERTS_URL => Http::response(['test-kid' => self::keys()['cert']], 200, ['Cache-Control' => 'public, max-age=20000'])]);
    }

    /** @return array{private: \OpenSSLAsymmetricKey, cert: string} a throwaway RSA key and a self-signed certificate for it */
    private static function keys(): array
    {
        if (! self::$keys) {
            $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'test'], $private, ['digest_alg' => 'sha256']);
            openssl_x509_export(openssl_csr_sign($csr, null, $private, 1, ['digest_alg' => 'sha256']), $cert);
            self::$keys = ['private' => $private, 'cert' => $cert];
        }

        return self::$keys;
    }

    private function idToken(array $claims = [], string $kid = 'test-kid'): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $claims = array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '1234567890',
            'email' => 'Maria@Gmail.com',
            'email_verified' => true,
            'name' => 'Maria Santos',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claims);
        $signing = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));
        openssl_sign($signing, $signature, self::keys()['private'], OPENSSL_ALGO_SHA256);

        return $signing.'.'.$b64($signature);
    }

    private function google(array $body): TestResponse
    {
        return $this->postJson('/api/v1/auth/google', $body + ['device_name' => 'Galaxy A15']);
    }

    public function test_new_google_account_asks_for_a_phone_then_creates_the_customer(): void
    {
        $token = $this->idToken();

        $this->google(['id_token' => $token])->assertOk()
            ->assertJsonPath('data.needs_phone', true)
            ->assertJsonPath('data.name', 'Maria Santos')
            ->assertJsonPath('data.email', 'maria@gmail.com')
            ->assertJsonMissingPath('data.token');
        $this->assertDatabaseCount('users', 0);

        $response = $this->google(['id_token' => $token, 'phone' => '+63 917 555 1234'])->assertCreated()
            ->assertJsonPath('data.user.email', 'maria@gmail.com')
            ->assertJsonPath('data.user.phone', '09175551234')
            ->assertJsonPath('data.user.google_linked', true)
            ->assertJsonPath('data.user.google_only', true)
            ->assertJsonMissingPath('data.user.google_id');

        $user = User::where('email', 'maria@gmail.com')->first();
        $this->assertSame('1234567890', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->customerProfile);
        $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();

        // Next time: straight in, by the Google account.
        $this->google(['id_token' => $this->idToken(['email' => 'renamed@gmail.com'])])->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_phone_is_validated_like_sign_up(): void
    {
        User::factory()->create(['phone' => '09170000001']);

        $this->google(['id_token' => $this->idToken(), 'phone' => '12345'])->assertJsonValidationErrors('phone');
        $this->google(['id_token' => $this->idToken(), 'phone' => '09170000001'])->assertJsonValidationErrors('phone');
    }

    public function test_existing_customer_with_the_same_email_is_signed_in_and_linked(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com', 'password' => 'secret123']);

        $this->google(['id_token' => $this->idToken()])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.google_only', false);

        $this->assertSame('1234567890', $user->fresh()->google_id);
        // The password still works too.
        $this->postJson('/api/v1/auth/login', ['email' => 'maria@gmail.com', 'password' => 'secret123'])->assertOk();
    }

    public function test_bad_tokens_are_refused(): void
    {
        User::factory()->create(['email' => 'maria@gmail.com']);

        $this->google(['id_token' => $this->idToken(['aud' => 'someone-elses-app'])])->assertJsonValidationErrors('id_token');
        $this->google(['id_token' => $this->idToken(['iss' => 'https://evil.example.com'])])->assertJsonValidationErrors('id_token');
        $this->google(['id_token' => $this->idToken(['exp' => time() - 3600])])->assertJsonValidationErrors('id_token');
        $this->google(['id_token' => $this->idToken(kid: 'unknown-kid')])->assertJsonValidationErrors('id_token');
        $this->google(['id_token' => $this->idToken(['email_verified' => false])])->assertJsonValidationErrors('id_token');
        $this->google(['id_token' => 'not.a.jwt'])->assertJsonValidationErrors('id_token');

        // Signed by someone else's key.
        [$head, $body] = explode('.', $this->idToken());
        openssl_sign("$head.$body", $sig, openssl_pkey_new(['private_key_bits' => 2048]), OPENSSL_ALGO_SHA256);
        $this->google(['id_token' => "$head.$body.".rtrim(strtr(base64_encode($sig), '+/', '-_'), '=')])->assertJsonValidationErrors('id_token');

        $this->assertNull(User::first()->google_id);
    }

    public function test_google_sign_in_is_off_without_a_client_id(): void
    {
        config(['services.google.client_ids' => '']);

        $this->google(['id_token' => $this->idToken()])->assertJsonValidationErrors('id_token');
    }

    public function test_staff_and_blocked_accounts_cannot_use_it(): void
    {
        User::factory()->create(['email' => 'maria@gmail.com', 'role' => 'admin']);
        $this->google(['id_token' => $this->idToken()])->assertForbidden();
        $this->assertNull(User::first()->google_id);

        User::first()->forceDelete();
        User::factory()->create(['email' => 'maria@gmail.com', 'status' => 'suspended']);
        $this->google(['id_token' => $this->idToken()])->assertForbidden();
    }

    public function test_an_email_linked_to_another_google_account_is_refused(): void
    {
        User::factory()->create(['email' => 'maria@gmail.com'])->forceFill(['google_id' => '999'])->save();

        $this->google(['id_token' => $this->idToken()])->assertForbidden();
    }

    public function test_google_only_customer_sets_a_first_password_and_deletes_without_one(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['google_id' => '1234567890'])->save();
        Sanctum::actingAs($user, ['customer']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => ''])->assertStatus(422);

        $this->putJson('/api/v1/auth/password', ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertOk();
        $this->assertTrue(password_verify('newpass123', $user->fresh()->password));

        // Now that there is a password, it is asked for.
        $this->putJson('/api/v1/auth/password', ['password' => 'other1234', 'password_confirmation' => 'other1234'])
            ->assertJsonValidationErrors('current_password');

        $user->forceFill(['password' => null])->save();
        $this->deleteJson('/api/v1/auth/account')->assertOk();
        $this->assertSoftDeleted($user);
    }
}
