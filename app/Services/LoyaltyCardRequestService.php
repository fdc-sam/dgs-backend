<?php

namespace App\Services;

use App\Enums\LoyaltyCardRequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCardRequest;
use App\Models\Payment;
use App\Models\User;
use App\Support\CustomerCode;
use App\Support\Fmt;
use App\Support\LoyaltyNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty card through a friend. A customer without a card scans the QR code of a friend who has an active card (the
 * endorser), pays the salon's share by GCash and uploads the receipt, and gives the endorser their share in cash. The
 * next free card number is set aside for the request; the admin checks the receipt against the salon's GCash and
 * approves (the card is linked for a year, the endorser has earned their share) or rejects it with a reason.
 * The card is digital: it shows in the app as soon as it is linked.
 */
class LoyaltyCardRequestService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly FileUploadService $uploads,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** Switched on in Settings, and the customer has somewhere to send the GCash payment. */
    public function isEnabled(): bool
    {
        return $this->settings->bool('loyalty_endorsement_enabled')
            && $this->gcashAmount() > 0
            && ($this->qrPath() !== null || filled($this->settings->get('payment_gcash_number')));
    }

    public function gcashAmount(): float
    {
        return (float) max(0, $this->settings->int('loyalty_endorsement_gcash_amount'));
    }

    public function endorserCash(): float
    {
        return (float) max(0, $this->settings->int('loyalty_endorsement_endorser_cash'));
    }

    /** What the app needs to show the offer and the payment screen (GET /settings → loyalty_endorsement). */
    public function appSettings(): array
    {
        $enabled = $this->isEnabled();

        return [
            'enabled' => $enabled,
            'card_price' => $this->gcashAmount() + $this->endorserCash(),
            'gcash_amount' => $this->gcashAmount(),
            'endorser_cash' => $this->endorserCash(),
            'gcash_account_name' => $enabled ? ($this->settings->get('payment_gcash_account_name') ?: null) : null,
            'gcash_number' => $enabled ? ($this->settings->get('payment_gcash_number') ?: null) : null,
            'gcash_qr_url' => $enabled ? Fmt::url($this->qrPath()) : null,
        ];
    }

    /** The card's own GCash QR if the admin uploaded one, else the salon's any-amount QR. */
    public function qrPath(): ?string
    {
        return ($this->settings->get('loyalty_endorsement_gcash_qr') ?: null) ?? ($this->settings->get('payment_gcash_qr') ?: null);
    }

    /**
     * The friend behind a scanned or typed code, once everything that would stop the request has been checked.
     *
     * @throws ApiException
     */
    public function endorserFor(User $customer, string $code): User
    {
        $this->assertCanRequest($customer);

        $normalized = CustomerCode::normalize($code);
        $endorser = $normalized ? User::customers()->where('qr_code', $normalized)->first() : null;
        if (! $endorser) {
            throw $this->codeError($normalized
                ? "We couldn't find a DGS customer with this QR code. Ask your friend to open Profile → My QR in the app."
                : "That isn't a DGS customer QR code. Ask your friend to open Profile → My QR in the app.");
        }
        if ($endorser->is($customer)) {
            throw $this->codeError("This is your own QR code. Scan the QR code of the friend who's endorsing you.");
        }
        if ($endorser->status !== UserStatus::Active) {
            throw $this->codeError('This customer account is not active.');
        }
        if (! $endorser->hasActiveLoyaltyCard()) {
            throw $this->codeError(LoyaltyCardRequest::shortName($endorser->name).' needs an active loyalty card to endorse you.');
        }

        return $endorser;
    }

    /** The customer's GCash receipt and their friend's code: a new request waiting for the admin, with a card number set aside. */
    public function submit(User $customer, string $code, string $reference, UploadedFile $proof): LoyaltyCardRequest
    {
        $reference = PaymentService::normalizeReference($reference);
        $endorser = $this->endorserFor($customer, $code);
        $this->assertReferenceUnused($reference);

        $path = $this->uploads->storePrivateImage($proof, 'payments');
        $request = DB::transaction(function () use ($customer, $endorser, $reference, $path) {
            // Two taps at once: the second one finds the first request.
            User::query()->whereKey($customer->id)->lockForUpdate()->first();
            $this->assertCanRequest($customer->fresh());

            return LoyaltyCardRequest::create([
                'customer_id' => $customer->id,
                'endorser_id' => $endorser->id,
                'loyalty_card_id' => $this->reserveCard($customer)?->id,
                'gcash_amount' => $this->gcashAmount(),
                'endorser_cash' => $this->endorserCash(),
                'reference_number' => $reference,
                'proof_path' => $path,
                'is_test' => (bool) $customer->is_test,
            ]);
        });

        $this->audit->log('loyalty_card_request.submitted', $request, null, [
            'endorser_id' => $endorser->id,
            'reference_number' => $reference,
            'gcash_amount' => (float) $request->gcash_amount,
            'card_number' => LoyaltyNumber::format($request->loyaltyCard?->number),
        ]);
        $this->notifications->loyaltyCardRequested($request->load(['customer', 'endorser']));

        return $request;
    }

    /** The payment checked out: link the card set aside (or the next free one) to the customer for a year. */
    public function approve(LoyaltyCardRequest $request, User $actor): LoyaltyCardRequest
    {
        $approved = DB::transaction(function () use ($request, $actor) {
            $locked = LoyaltyCardRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'This request was already '.strtolower($locked->status->label()).'.']);
            }
            $customer = User::withTrashed()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer->trashed() || ! $customer->isCustomer()) {
                throw ValidationException::withMessages(['request' => 'This customer account was deleted. Reject the request instead.']);
            }
            if ($customer->hasLoyaltyCard()) {
                throw ValidationException::withMessages(['request' => "{$customer->name} already has loyalty card ".LoyaltyNumber::format($customer->loyalty_number)
                    .'. Reject this request, or disconnect that card first.']);
            }

            $card = $locked->loyalty_card_id ? LoyaltyCard::query()->whereKey($locked->loyalty_card_id)->lockForUpdate()->first() : null;
            if (! $card || ($card->customer_id && $card->customer_id !== $customer->id)) {
                $card = $this->reserveCard($customer, except: $locked->id); // linked by hand to someone else meanwhile
            }

            $old = ['loyalty_number' => $customer->loyalty_number, 'loyalty_expires_at' => $customer->loyalty_expires_at?->toDateString()];
            $customer->forceFill(['loyalty_number' => $card->number])->save(); // starts the year (User::saving)
            $card->update(['customer_id' => $customer->id, 'is_test' => $customer->is_test]);
            $locked->update([
                'loyalty_card_id' => $card->id,
                'status' => LoyaltyCardRequestStatus::Approved,
                'rejection_reason' => null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            $this->audit->log('customer.loyalty_number', $customer, $old, [
                'loyalty_number' => $customer->loyalty_number,
                'loyalty_expires_at' => $customer->loyalty_expires_at?->toDateString(),
            ]);
            $this->audit->log('loyalty_card_request.approved', $locked, ['status' => LoyaltyCardRequestStatus::Pending->value], [
                'status' => LoyaltyCardRequestStatus::Approved->value,
                'card_number' => LoyaltyNumber::format($card->number),
                'endorser_cash' => (float) $locked->endorser_cash,
            ]);

            return $locked;
        });

        $this->notifications->loyaltyCardRequestApproved($approved->load(['customer', 'endorser', 'loyaltyCard']));

        return $approved;
    }

    /** The payment could not be verified: the customer sees the reason and can send a new receipt; the card number is freed. */
    public function reject(LoyaltyCardRequest $request, User $actor, string $reason): LoyaltyCardRequest
    {
        $reason = trim($reason);
        $rejected = DB::transaction(function () use ($request, $actor, $reason) {
            $locked = LoyaltyCardRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'This request was already '.strtolower($locked->status->label()).'.']);
            }
            $locked->update([
                'status' => LoyaltyCardRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->audit->log('loyalty_card_request.rejected', $locked, ['status' => LoyaltyCardRequestStatus::Pending->value], [
                'status' => LoyaltyCardRequestStatus::Rejected->value,
                'reason' => $reason,
            ]);

            return $locked;
        });

        $this->notifications->loyaltyCardRequestRejected($rejected->load('customer'));

        return $rejected;
    }

    /**
     * The endorser's earnings: cash from friends whose cards were approved, what is still waiting, and every request.
     *
     * @return array{can_endorse: bool, endorser_cash: float, summary: array<string, int|float>, items: \Illuminate\Support\Collection<int, LoyaltyCardRequest>}
     */
    public function endorsements(User $endorser): array
    {
        $items = LoyaltyCardRequest::query()->where('endorser_id', $endorser->id)->with('customer')
            ->latest()->orderByDesc('id')->limit(200)->get();
        $approved = $items->where('status', LoyaltyCardRequestStatus::Approved);
        $pending = $items->where('status', LoyaltyCardRequestStatus::Pending);

        return [
            'can_endorse' => $this->isEnabled() && $endorser->hasActiveLoyaltyCard(),
            'endorser_cash' => $this->endorserCash(),
            'summary' => [
                'earned' => round((float) $approved->sum('endorser_cash'), 2),
                'approved_count' => $approved->count(),
                'pending' => round((float) $pending->sum('endorser_cash'), 2),
                'pending_count' => $pending->count(),
            ],
            'items' => $items,
        ];
    }

    /** Pending requests hold their card number, so a manual link or another request does not take it. */
    public static function reservedCardIds(): \Illuminate\Database\Eloquent\Builder
    {
        return LoyaltyCardRequest::query()->pending()->whereNotNull('loyalty_card_id')->select('loyalty_card_id');
    }

    /** @throws ApiException */
    private function assertCanRequest(User $customer): void
    {
        if (! $this->isEnabled()) {
            throw ApiException::conflict('Getting a loyalty card through a friend is not available right now. Please ask at the salon.');
        }
        if ($customer->hasActiveLoyaltyCard()) {
            throw ApiException::conflict('You already have a loyalty card.');
        }
        if ($customer->hasLoyaltyCard()) {
            throw ApiException::conflict('Your loyalty card has expired. Please renew it at the salon.');
        }
        if (LoyaltyCardRequest::query()->where('customer_id', $customer->id)->pending()->exists()) {
            throw ApiException::conflict("Your loyalty card request is already with the salon. We'll let you know once it's approved.");
        }
    }

    private function codeError(string $message): ApiException
    {
        return ApiException::validation($message, ['code' => [$message]]);
    }

    /** A GCash Ref No. pays for one thing: not reused from a booking deposit or another card request (unless rejected). */
    private function assertReferenceUnused(string $reference): void
    {
        $taken = Payment::query()->where('reference_number', $reference)->where('status', '!=', Payment::REJECTED)->exists()
            || LoyaltyCardRequest::withoutGlobalScopes()->where('reference_number', $reference)
                ->where('status', '!=', LoyaltyCardRequestStatus::Rejected->value)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['reference_number' => 'This reference number was already used for another payment.']);
        }
    }

    /**
     * The lowest free card on the customer's test/live side that no other waiting request holds; a new number after the
     * highest one when there are none left (as Loyalty cards → Generate would make).
     */
    private function reserveCard(User $customer, ?string $except = null): LoyaltyCard
    {
        $card = LoyaltyCard::withoutGlobalScopes()
            ->where('is_test', (bool) $customer->is_test)
            ->whereNull('customer_id')
            ->whereNotIn('id', self::reservedCardIds()->withoutGlobalScopes()->when($except, fn ($q) => $q->whereKeyNot($except)))
            ->orderBy('number')->lockForUpdate()->first();
        if ($card) {
            return $card;
        }

        $digits = (int) config('salon.loyalty.max_digits');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = 1 + max(
                (int) LoyaltyCard::withoutGlobalScopes()->max('number'),
                (int) User::withoutGlobalScopes()->whereNotNull('loyalty_number')->max('loyalty_number'),
            );
            try {
                return DB::transaction(fn () => LoyaltyCard::create([
                    'number' => str_pad((string) $next, $digits, '0', STR_PAD_LEFT),
                    'is_test' => (bool) $customer->is_test,
                ]));
            } catch (QueryException $e) {
                // Someone took that number at the same moment: try the next one.
            }
        }

        throw ApiException::conflict('No loyalty card number is free right now. Please try again.');
    }
}
