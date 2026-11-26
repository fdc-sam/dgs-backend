<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Google ID token (the JWT the app gets from "Sign in with Google") the way Google documents: an RS256
 * signature by one of Google's current keys, issued by accounts.google.com, for one of our OAuth client ids, not expired.
 * https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
 */
class GoogleIdTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const LEEWAY = 60;

    /** @return array<string, mixed>|null the token's claims (sub, email, email_verified, name, picture, ...), or null when it is not valid */
    public function verify(string $idToken): ?array
    {
        $audiences = $this->audiences();
        $parts = explode('.', $idToken);
        if ($audiences === [] || count($parts) !== 3) {
            return null;
        }
        [$head64, $body64, $sig64] = $parts;
        $header = json_decode((string) $this->base64UrlDecode($head64), true);
        $claims = json_decode((string) $this->base64UrlDecode($body64), true);
        $signature = $this->base64UrlDecode($sig64);
        if (! is_array($header) || ! is_array($claims) || $signature === null || ($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $cert = $this->certificate((string) ($header['kid'] ?? ''));
        if (! $cert || openssl_verify("{$head64}.{$body64}", $signature, $cert, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $now = time();
        $valid = in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && in_array($claims['aud'] ?? null, $audiences, true)
            && is_numeric($claims['exp'] ?? null) && $claims['exp'] + self::LEEWAY >= $now
            && (! isset($claims['iat']) || $claims['iat'] - self::LEEWAY <= $now)
            && filled($claims['sub'] ?? null);

        return $valid ? $claims : null;
    }

    /** @return list<string> the OAuth client ids a token may be issued for (the app's "web" client id) */
    public function audiences(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.google.client_ids')))));
    }

    private function certificate(string $kid): ?string
    {
        if ($kid === '') {
            return null;
        }
        $certs = Cache::get('google-id-token-certs');
        if (! is_array($certs) || ! isset($certs[$kid])) {
            // Not cached yet, or Google rotated its keys: fetch them again.
            $certs = $this->fetchCertificates();
        }

        return $certs[$kid] ?? null;
    }

    /** @return array<string, string> key id => PEM certificate */
    private function fetchCertificates(): array
    {
        try {
            $response = Http::timeout(10)->get(self::CERTS_URL);
        } catch (Throwable) {
            return [];
        }
        $certs = $response->successful() ? $response->json() : null;
        if (! is_array($certs) || $certs === []) {
            return [];
        }
        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);
        Cache::put('google-id-token-certs', $certs, max(60, min((int) ($m[1] ?? 3600), 86400)));

        return $certs;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
