<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AppointmentItem;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerQrService;
use App\Services\LoyaltyCardRequestService;
use App\Support\CustomerCode;
use App\Support\LoyaltyNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = User::customers()
            ->withCount(['appointments', 'appointments as completed_count' => fn ($q) => $q->where('status', AppointmentStatus::Completed->value)])
            ->withSum(['appointments as lifetime_value' => fn ($q) => $q->where('status', AppointmentStatus::Completed->value)], 'total_amount')
            ->withMax('appointments as last_visit', 'starts_at')
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                // A scanned or typed QR code (any case, with or without dashes) finds its owner exactly.
                $code = CustomerCode::normalize($term);
                // So does a loyalty card number, typed with or without the LC- prefix.
                $card = LoyaltyNumber::normalize($term);
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('phone', $like)
                    ->when($code, fn ($q) => $q->orWhere('qr_code', $code))
                    ->when(ctype_digit($card), fn ($q) => $q->orWhere('loyalty_number', $card)));
            })
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.customers.index', ['customers' => $customers]);
    }

    public function show(User $customer): View
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('view', $customer);

        $customer->load(['customerProfile', 'favorites.favoritable']);

        return view('admin.customers.show', [
            'customer' => $customer,
            // Free cards, minus the ones set aside for other customers' requests through a friend.
            'loyaltyCards' => LoyaltyCard::query()->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', $customer->id))
                ->whereNotIn('id', LoyaltyCardRequestService::reservedCardIds()->where('customer_id', '!=', $customer->id))
                ->orderBy('number')->get(),
            'appointments' => $customer->appointments()->with(['items', 'branch', 'staff.user'])->orderByDesc('starts_at')->paginate(self::PER_PAGE),
            'completedServices' => AppointmentItem::query()
                ->whereHas('appointment', fn ($q) => $q->where('customer_id', $customer->id)->where('status', AppointmentStatus::Completed->value))
                ->selectRaw('service_name, COUNT(*) as times, MAX(created_at) as last_at')
                ->groupBy('service_name')->orderByDesc('times')->get(),
            'reviews' => $customer->reviews()->with('service')->latest()->get(),
            'stats' => [
                'total' => $customer->appointments()->count(),
                'completed' => $customer->appointments()->where('status', 'completed')->count(),
                'cancelled' => $customer->appointments()->where('status', 'cancelled')->count(),
                'no_show' => $customer->appointments()->where('status', 'no_show')->count(),
                'value' => (float) $customer->appointments()->where('status', 'completed')->sum('total_amount'),
            ],
        ]);
    }

    /** The customer's QR code image (shown on their page; `?download=1` saves it for printing or sending). */
    public function qr(Request $request, User $customer, CustomerQrService $qr): Response
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('view', $customer);

        return $qr->response($customer, $request, download: $request->boolean('download'));
    }

    /** Assign an available inventory card, accept an existing printed number, or clear the customer's card. */
    public function updateLoyaltyCard(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('update', $customer);
        $request->merge(['loyalty_number' => LoyaltyNumber::normalize($request->input('loyalty_number'))]);
        $data = $request->validate([
            'loyalty_card_id' => ['nullable', 'string', 'exists:loyalty_cards,id'],
            'loyalty_number' => $request->filled('loyalty_number') ? LoyaltyNumber::rules($customer) : ['nullable'],
        ], LoyaltyNumber::messages());

        $old = $this->cardValues($customer);
        $number = DB::transaction(function () use ($customer, $data) {
            $number = $data['loyalty_number'] ?: null;
            $card = null;
            if (! empty($data['loyalty_card_id'])) {
                $card = LoyaltyCard::query()->whereKey($data['loyalty_card_id'])->lockForUpdate()->firstOrFail();
                if ($card->customer_id && $card->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['loyalty_card_id' => 'That card is already assigned to another customer.']);
                }
                $number = $card->number;
            } elseif ($number) {
                $card = LoyaltyCard::withoutGlobalScopes()->where('number', $number)->lockForUpdate()->first();
                if ($card && $card->customer_id && $card->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['loyalty_number' => 'That card is already assigned to another customer.']);
                }
                $card ??= LoyaltyCard::create(['number' => $number, 'created_by' => auth()->id(), 'is_test' => $customer->is_test]);
            }

            $previous = LoyaltyCard::withoutGlobalScopes()->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($previous && $previous->number !== $number) {
                $previous->update(['customer_id' => null]);
            }

            $customer->forceFill(['loyalty_number' => $number])->save();
            if ($card && $card->customer_id !== $customer->id) {
                $card->update(['customer_id' => $customer->id, 'is_test' => $customer->is_test]);
            }

            return $number;
        });
        $audit->log('customer.loyalty_number', $customer, $old, $this->cardValues($customer));

        return back()->with('success', $customer->loyalty_number
            ? "Loyalty card {$this->cardNumber($customer)} linked to {$customer->name}. It expires on {$this->expiresOn($customer)}."
            : "Loyalty card removed from {$customer->name}.");
    }

    /** Another year on the customer's card, once it has expired (or in its last weeks; config salon.loyalty). */
    public function renewLoyaltyCard(User $customer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('update', $customer);
        if (! $customer->hasLoyaltyCard()) {
            return back()->with('error', "{$customer->name} has no loyalty card to renew.");
        }
        if (! $customer->loyaltyCardRenewable()) {
            return back()->with('error', "Loyalty card {$this->cardNumber($customer)} does not expire until {$this->expiresOn($customer)}. "
                .'It can be renewed in its last '.config('salon.loyalty.renewal_window_days').' days or once it has expired.');
        }

        $old = $this->cardValues($customer);
        $customer->renewLoyaltyCard();
        $audit->log('customer.loyalty_renewed', $customer, $old, $this->cardValues($customer));

        return back()->with('success', "Loyalty card {$this->cardNumber($customer)} renewed. It now expires on {$this->expiresOn($customer)}.");
    }

    private function cardNumber(User $customer): string
    {
        return LoyaltyNumber::format($customer->loyalty_number) ?? '';
    }

    private function expiresOn(User $customer): string
    {
        return $customer->loyalty_expires_at?->format('M j, Y') ?? '';
    }

    /** @return array<string, ?string> */
    private function cardValues(User $customer): array
    {
        return ['loyalty_number' => $customer->loyalty_number, 'loyalty_expires_at' => $customer->loyalty_expires_at?->toDateString()];
    }

    public function updateStatus(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('changeStatus', $customer);
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        $old = $customer->status->value;
        $customer->update(['status' => $data['status']]);
        if ($data['status'] !== UserStatus::Active->value) {
            $customer->tokens()->delete(); // sign out of the app immediately
        }
        $audit->log('customer.status', $customer, ['status' => $old], ['status' => $data['status']]);

        return back()->with('success', 'Customer status updated.');
    }

    /** Super admins put a customer on the test side (seen only by test accounts) or back on live. */
    public function updateAccountType(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('super-admin');
        $data = $request->validate(['account_type' => ['required', Rule::in(['live', 'test'])]]);
        $test = $data['account_type'] === 'test';
        if ($customer->is_test === $test) {
            return back();
        }

        $customer->setTestAccount($test);
        $audit->log('customer.account_type', $customer, ['is_test' => ! $test], ['is_test' => $test]);

        // The customer is on the other side now, so this admin can no longer open their page.
        $side = $test ? 'test' : 'live';

        return redirect()->route('customers.index')
            ->with('success', "{$customer->name} is now a {$side} account. Only {$side} accounts can see them and their bookings.");
    }
}
