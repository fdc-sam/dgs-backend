<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCardRequest;
use App\Services\InviteService;
use App\Services\LoyaltyCardRequestService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * A customer's invite link (/i/{code}), shared from Invites in the app. On Android it opens the app with the code
 * (an intent link; directly, without this page, once assetlinks.json lists the app's certificate), or the Play Store,
 * which hands the code to the app as the install referrer so the friend signs up with it.
 */
class InviteController extends Controller
{
    public function show(string $code, InviteService $invites, LoyaltyCardRequestService $requests, SettingsService $settings): View
    {
        $inviter = $invites->inviterFor($code);
        $code = $inviter?->invite_code ?? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $package = (string) config('salon.android.package');
        $play = 'https://play.google.com/store/apps/details?'.http_build_query(['id' => $package, 'referrer' => 'invite_code='.$code]);
        $link = route('invite.show', ['code' => $code]);
        $parts = parse_url($link);
        $intent = 'intent://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '')
            .'#Intent;scheme='.($parts['scheme'] ?? 'https').';package='.$package.';S.browser_fallback_url='.rawurlencode($play).';end';

        return view('site.invite', [
            's' => $settings->all(),
            'logoUrl' => $settings->logoUrl(),
            'inviterName' => $inviter ? LoyaltyCardRequest::shortName($inviter->name) : null,
            'code' => $inviter ? $code : null,
            'cardPrice' => $requests->cardPrice(),
            'membershipOffered' => $requests->isEnabled(),
            'playUrl' => $play,
            'intentUrl' => $intent,
            'claimDays' => (int) config('salon.invites.claim_days', 7),
        ]);
    }

    /** Digital Asset Links: lets Android open /i/… links straight in the app (config salon.android). */
    public function assetLinks(): JsonResponse
    {
        $fingerprints = config('salon.android.cert_sha256', []);
        $statements = $fingerprints ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('salon.android.package'),
                'sha256_cert_fingerprints' => array_values($fingerprints),
            ],
        ]] : [];

        return response()->json($statements)->header('Cache-Control', 'public, max-age=3600');
    }
}
