<?php

namespace App\Services\Fcm;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Minimal Firebase Cloud Messaging HTTP v1 client using a service-account JWT (RS256 via OpenSSL). */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function isConfigured(): bool
    {
        $config = config('services.fcm');

        return filled($config['project_id']) && filled($config['client_email']) && filled($config['private_key']);
    }

    /** @param  list<string>  $tokens */
    public function send(array $tokens, FcmMessage $message): void
    {
        if (! $this->isConfigured() || $tokens === []) {
            return;
        }

        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', config('services.fcm.project_id'));
        $accessToken = $this->accessToken();

        foreach (array_unique($tokens) as $token) {
            $response = Http::withToken($accessToken)->timeout(10)->post($url, [
                'message' => [
                    'token' => $token,
                    // Android handles this data-only message in DgsMessagingService so the app
                    // can render the same branded notification while foregrounded or backgrounded.
                    'data' => array_map('strval', $message->data + ['title' => $message->title, 'body' => $message->body]),
                    'android' => ['priority' => 'high'],
                    'apns' => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => [
                            'aps' => [
                                'alert' => ['title' => $message->title, 'body' => $message->body],
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

            if ($response->successful()) {
                continue;
            }

            $status = $response->json('error.status');
            if (in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true) || $response->status() === 404) {
                // Token is stale (app uninstalled / token rotated) — stop sending to it.
                DeviceToken::where('token', $token)->delete();
            } else {
                Log::warning('FCM send failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);
            }
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () {
            $now = time();
            $jwt = $this->jwt([
                'iss' => config('services.fcm.client_email'),
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Unable to obtain FCM access token: '.$response->json('error_description', 'unknown error'));
            }

            return $response->json('access_token');
        });
    }

    private function jwt(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        $key = openssl_pkey_get_private(config('services.fcm.private_key'));
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Invalid FCM private key.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
