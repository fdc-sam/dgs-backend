<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\LoyaltyCardRequest;
use App\Services\InviteService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invite a friend to DGS Membership: the customer's own link and code, the friends who joined or became members
 * through it, and (within a week of signing up) typing the code of the friend who invited them. The public lookup lets
 * the sign-up screen say whose invite it is.
 */
class InviteController extends ApiController
{
    public function __construct(private readonly InviteService $invites) {}

    public function show(Request $request): JsonResponse
    {
        $page = $this->invites->page($request->user());

        return $this->ok([
            ...$page,
            'enter_code_until' => Fmt::iso($page['enter_code_until']),
            'items' => $page['items']->map(fn (array $item) => [
                ...$item,
                'created_at' => Fmt::iso($item['created_at']),
                'reviewed_at' => Fmt::iso($item['reviewed_at']),
            ])->values(),
        ]);
    }

    /** Signed in without the link: the friend's code, typed on the Invites page. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']], ['code.required' => 'Enter your friend\'s invite code.']);
        $inviter = $this->invites->claim($request->user(), $data['code']);
        $name = LoyaltyCardRequest::shortName($inviter->name);

        return $this->ok(['invited_by' => ['name' => $name, 'code' => $inviter->invite_code]], "Thanks! {$name} invited you.");
    }

    /** Before signing up (no token): whose invite a link carried, so the app can greet the friend by name. */
    public function lookup(string $code): JsonResponse
    {
        $inviter = $this->invites->inviterFor($code);
        if (! $inviter) {
            return $this->ok(['valid' => false, 'name' => null]);
        }

        return $this->ok(['valid' => true, 'name' => LoyaltyCardRequest::shortName($inviter->name), 'code' => $inviter->invite_code]);
    }
}
