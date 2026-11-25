<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCardRequest;
use App\Services\InviteCardService;
use App\Services\InviteService;
use App\Services\LoyaltyCardRequestService;
use App\Services\SettingsService;
use App\Support\Fmt;
use App\Support\InviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'cardUrl' => route('invite.card', ['code' => $code]),
            // The address chat apps file the preview under: the shared one, with its preview version.
            'shareUrl' => InviteService::linkFor($code),
            ...$this->shareText($settings->all()['salon_name'], $requests, $inviter?->invite_code ?? InviteCode::normalize($code)),
        ]);
    }

    /**
     * What the shared link says in a chat, in the sharer's words like the app's old share message. Messages (iPhone)
     * shows only the title under the picture, so the offer goes in the title; Messenger and Viber add the description.
     * The code comes from the link itself, so test accounts' links (unknown to guests) still show it.
     *
     * @return array{shareTitle: string, shareDescription: string}
     */
    private function shareText(string $salon, LoyaltyCardRequestService $requests, ?string $code): array
    {
        $codeText = $code ? " Invite code {$code}." : '';
        if (! $requests->isEnabled()) {
            return [
                'shareTitle' => "Join me at {$salon}!",
                'shareDescription' => "Get the {$salon} app with my invite and book your hair and spa services.".$codeText,
            ];
        }
        $price = Fmt::peso($requests->cardPrice());

        // Word for word the app's share message, so the preview reads the same as before.
        return [
            'shareTitle' => "Join me at {$salon}! Get the app with my invite and become a member for {$price} to get member prices for a whole year.",
            'shareDescription' => $code ? "Invite code {$code}" : "Get the {$salon} app and become a member.",
        ];
    }

    /**
     * The invite card picture (/i/{code}/card.jpg) the link shows in chat apps: who invited, the price, the code.
     * Unknown codes get a generic card, so a mistyped link still previews nicely. Drawn once per inviter, name, price
     * and design, then served from storage/app/cache, so link previews (which give up on slow pictures) get it at once.
     */
    public function card(string $code, Request $request, InviteService $invites, LoyaltyCardRequestService $requests, InviteCardService $cards): Response
    {
        $inviter = $invites->inviterFor($code);
        $name = $inviter ? LoyaltyCardRequest::shortName($inviter->name) : null;
        $offer = $requests->isEnabled() ? Fmt::peso($requests->cardPrice()).' · member prices on your services for a whole year' : null;
        $shownCode = $inviter?->invite_code ?? InviteCode::normalize($code);
        $key = md5(InviteCardService::RENDER_VERSION.'|'.$name.'|'.$shownCode.'|'.$offer.'|'.@filemtime(public_path('images/og-share.png')));
        $headers = ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=86400', 'ETag' => '"'.$key.'"'];
        if (trim((string) $request->header('If-None-Match')) === '"'.$key.'"') {
            return response('', 304, $headers);
        }

        $file = storage_path('app/cache/invite-cards/'.$key.'.jpg');
        $jpeg = is_file($file) ? (string) @file_get_contents($file) : '';
        if ($jpeg === '') {
            $jpeg = $cards->jpeg($name, $shownCode, $offer);
            @mkdir(dirname($file), 0775, true);
            @file_put_contents($file, $jpeg, LOCK_EX);
        }

        return response($jpeg, 200, $headers);
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
