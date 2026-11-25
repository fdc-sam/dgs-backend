<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\LoyaltyCardRequest;
use App\Models\User;
use App\Support\CustomerCode;
use App\Support\InviteCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invite a friend to DGS Membership. Every customer shares a link (/i/{invite_code}); a friend who signs up through
 * it (or types the code within a week) remembers who invited them, so their membership payment goes through that
 * friend without scanning anything, and the friend earns the reward once the salon approves it
 * (LoyaltyCardRequestService). The old QR code still works wherever a code is typed.
 */
class InviteService
{
    public function __construct(
        private readonly LoyaltyCardRequestService $requests,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Bumped whenever what a shared link previews changes (title, picture). Chat apps (Messenger, iPhone Messages…)
     * keep the first preview they made for an address and never read the page again, so a new version is a new
     * address for them. The app shares the link the API gives it, so no app update is needed.
     */
    public const PREVIEW_VERSION = 2;

    /** The link a customer shares, on this server (APP_URL): it opens the app, or the Play Store with the code. */
    public function link(User $customer): string
    {
        return self::linkFor((string) $customer->invite_code);
    }

    /** /i/MARIA7K2M?v=2: the path carries the code (the app reads it), the version only renews chat previews. */
    public static function linkFor(string $code): string
    {
        return route('invite.show', ['code' => $code, 'v' => self::PREVIEW_VERSION]);
    }

    /** An active customer behind an invite code (or, as before, the code in their QR), or null. */
    public function inviterFor(?string $code): ?User
    {
        $qr = CustomerCode::normalize((string) $code);
        $invite = $qr ? null : InviteCode::normalize($code);
        if (! $qr && ! $invite) {
            return null;
        }

        return User::customers()->where('status', UserStatus::Active)
            ->where($qr ? 'qr_code' : 'invite_code', $qr ?? $invite)->first();
    }

    /** At the counter: staff type the friend's invite code or mobile number (09171234567 or +639171234567). */
    public function inviterForStaff(?string $input): ?User
    {
        $byCode = $this->inviterFor($input);
        if ($byCode) {
            return $byCode;
        }
        $digits = (string) preg_replace('/[\s\-().]/', '', (string) $input);
        $phone = preg_match('/^\+?639\d{9}$/', $digits) ? '0'.substr($digits, -10) : $digits;

        return preg_match('/^09\d{9}$/', $phone)
            ? User::customers()->where('status', UserStatus::Active)->where('phone', $phone)->first()
            : null;
    }

    /** Staff recorded who invited this customer (Customers → Membership). */
    public function attachByStaff(User $customer, User $inviter): void
    {
        $this->attach($customer, $inviter, 'invite.by_staff', notify: false);
    }

    /** At sign-up: remember who invited this customer. A stale or wrong code never stops the sign-up. */
    public function attachOnSignup(User $customer, ?string $code): void
    {
        $inviter = filled($code) ? $this->inviterFor($code) : null;
        if (! $inviter || $inviter->is($customer)) {
            return;
        }
        $this->attach($customer, $inviter, 'invite.signup');
    }

    /**
     * Typed on the Invites page by a customer who signed up without the link: the code of the friend who invited them.
     *
     * @throws ValidationException
     */
    public function claim(User $customer, string $code): User
    {
        if (! $this->canEnterCode($customer)) {
            throw $this->codeError($customer->invited_by_id
                ? 'You already joined through a friend\'s invite.'
                : 'Invite codes can only be added in your first '.$this->claimDays().' days, before you become a member.');
        }
        $inviter = $this->inviterFor($code);
        if (! $inviter) {
            throw $this->codeError("We couldn't find that invite code. Check it with your friend and try again.");
        }
        if ($inviter->is($customer)) {
            throw $this->codeError('This is your own invite code. Enter the code of the friend who invited you.');
        }

        $this->attach($customer, $inviter, 'invite.claimed');

        return $inviter;
    }

    /** No inviter yet, signed up in the last week, and never a member nor asked to be one. */
    public function canEnterCode(User $customer): bool
    {
        return $customer->invited_by_id === null
            && ! $customer->hasLoyaltyCard()
            && $customer->created_at !== null && $customer->created_at->gt(now()->subDays($this->claimDays()))
            && ! LoyaltyCardRequest::withoutGlobalScopes()->where('customer_id', $customer->id)->exists();
    }

    /** The last moment the customer can still enter a code, or null when they can't. */
    public function enterCodeUntil(User $customer): ?CarbonInterface
    {
        return $this->canEnterCode($customer) ? $customer->created_at->copy()->addDays($this->claimDays()) : null;
    }

    /**
     * Everything the Invites page shows: the customer's link and code, the reward, who invited them, and every friend
     * who joined or paid for a membership through them (newest first).
     *
     * @return array<string, mixed>
     */
    public function page(User $customer): array
    {
        $endorsements = $this->requests->endorsements($customer);
        /** @var Collection<int, LoyaltyCardRequest> $requests */
        $requests = $endorsements['items'];
        $requested = $requests->pluck('customer_id')->unique();

        // Friends who joined but haven't paid for a membership through this customer yet.
        $joined = $customer->invitees()->whereNotIn('id', $requested)->latest('invited_at')->limit(200)->get();

        $items = $requests->map(fn (LoyaltyCardRequest $r) => [
            'id' => $r->id,
            'friend_name' => $r->customer?->name,
            'status' => $r->status->value,
            'status_label' => $r->status->customerLabel(),
            'amount' => (float) $r->endorser_cash,
            'created_at' => $r->created_at,
            'reviewed_at' => $r->reviewed_at,
        ])->concat($joined->map(fn (User $friend) => [
            'id' => $friend->id,
            'friend_name' => $friend->name,
            'status' => 'joined',
            'status_label' => 'Joined',
            'amount' => $endorsements['endorser_cash'],
            'created_at' => $friend->invited_at ?? $friend->created_at,
            'reviewed_at' => null,
        ]))->sortByDesc(fn (array $item) => $item['created_at']?->getTimestamp() ?? 0)->values();

        $inviter = $customer->invitedBy;

        return [
            'code' => $customer->invite_code,
            'link' => $this->link($customer),
            'can_endorse' => $endorsements['can_endorse'],
            'reward' => $endorsements['endorser_cash'],
            'card_price' => $this->requests->cardPrice(),
            'invited_by' => $inviter ? ['name' => LoyaltyCardRequest::shortName($inviter->name), 'code' => $inviter->invite_code] : null,
            'can_enter_code' => $this->canEnterCode($customer),
            'enter_code_until' => $this->enterCodeUntil($customer),
            'summary' => $endorsements['summary'] + ['joined_count' => $joined->count()],
            'items' => $items,
        ];
    }

    private function attach(User $customer, User $inviter, string $action, bool $notify = true): void
    {
        DB::transaction(function () use ($customer, $inviter) {
            $locked = User::query()->whereKey($customer->id)->lockForUpdate()->first();
            if ($locked?->invited_by_id !== null) {
                return;
            }
            $customer->forceFill(['invited_by_id' => $inviter->id, 'invited_at' => now()])->save();
        });
        if ($customer->invited_by_id !== $inviter->id) {
            return;
        }

        $this->audit->log($action, $customer, null, ['invited_by_id' => $inviter->id]);
        if ($notify) {
            $this->notifications->friendJoined($inviter, $customer);
        }
    }

    private function claimDays(): int
    {
        return (int) config('salon.invites.claim_days', 7);
    }

    private function codeError(string $message): ValidationException
    {
        return ValidationException::withMessages(['code' => $message]);
    }
}
