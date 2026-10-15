<?php

namespace App\Services\Supabase;

use App\Models\User;
use RuntimeException;

/**
 * Issues short-lived Supabase JWTs so the mobile app can subscribe to Realtime.
 *
 * Users authenticate with Laravel (Sanctum), not Supabase Auth. This token only carries
 * `role=authenticated` and `sub=<user id>`; the RLS policy on `notifications` lets that role read
 * rows where notifiable_id = sub, and nothing else (every other table is revoked from it).
 */
class RealtimeTokenService
{
    public function isConfigured(): bool
    {
        return filled(config('services.supabase.url'))
            && filled(config('services.supabase.publishable_key'))
            && filled(config('services.supabase.jwt_secret'));
    }

    /** @return array{token: string, expires_at: int} */
    public function issue(User $user): array
    {
        $secret = (string) config('services.supabase.jwt_secret');
        if ($secret === '') {
            throw new RuntimeException('SUPABASE_JWT_SECRET is not configured.');
        }

        $now = time();
        $expires = $now + max(300, (int) config('services.supabase.realtime_token_ttl', 3600));

        $claims = [
            'iss' => rtrim((string) config('services.supabase.url'), '/').'/auth/v1',
            'sub' => $user->id,
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'iat' => $now,
            'exp' => $expires,
        ];

        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $unsigned, $secret, true)), '+/', '-_'), '=');

        return ['token' => $unsigned.'.'.$signature, 'expires_at' => $expires];
    }
}
