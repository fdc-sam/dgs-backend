<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\LoyaltyNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoyaltyCardController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,available,assigned'],
        ]);
        $cards = LoyaltyCard::query()->with('customer')
            ->when(($filters['status'] ?? 'all') === 'available', fn ($q) => $q->whereNull('customer_id'))
            ->when(($filters['status'] ?? 'all') === 'assigned', fn ($q) => $q->whereNotNull('customer_id'))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = LoyaltyNumber::normalize($term);
                $q->where(fn ($q) => $q->whereLike('number', $like)
                    ->when(is_string($digits) && ctype_digit($digits), fn ($q) => $q->orWhere('number', $digits))
                    ->orWhereHas('customer', fn ($customer) => $customer->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('phone', $like)));
            })
            ->orderBy('number')->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.loyalty-cards.index', [
            'cards' => $cards,
            'availableCount' => LoyaltyCard::query()->whereNull('customer_id')->count(),
            'assignedCount' => LoyaltyCard::query()->whereNotNull('customer_id')->count(),
        ]);
    }

    public function generate(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000']]);
        $digits = (int) config('salon.loyalty.max_digits');
        $maxExisting = max(
            (int) LoyaltyCard::withoutGlobalScopes()->max('number'),
            (int) User::withoutGlobalScopes()->whereNotNull('loyalty_number')->max('loyalty_number'),
        );
        $first = $maxExisting + 1;
        if ($first + $data['quantity'] - 1 > (10 ** min($digits, 15)) - 1) {
            throw ValidationException::withMessages(['quantity' => 'There are not enough unused card numbers left at the configured number length.']);
        }

        DB::transaction(function () use ($data, $first, $digits, $request) {
            for ($number = $first; $number < $first + $data['quantity']; $number++) {
                LoyaltyCard::create([
                    'number' => str_pad((string) $number, $digits, '0', STR_PAD_LEFT),
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        $last = str_pad((string) ($first + $data['quantity'] - 1), $digits, '0', STR_PAD_LEFT);
        $start = str_pad((string) $first, $digits, '0', STR_PAD_LEFT);
        $audit->log('loyalty_cards.generated', 'loyalty_cards', null, [
            'quantity' => $data['quantity'],
            'first_number' => LoyaltyNumber::format($start),
            'last_number' => LoyaltyNumber::format($last),
        ], 'batch');

        return back()->with('success', $data['quantity'].' loyalty cards generated: '.LoyaltyNumber::format($start).'–'.LoyaltyNumber::format($last).'.');
    }

    public function disconnect(LoyaltyCard $loyaltyCard, AuditLogger $audit): RedirectResponse
    {
        $result = DB::transaction(function () use ($loyaltyCard) {
            $card = LoyaltyCard::query()->whereKey($loyaltyCard->id)->lockForUpdate()->firstOrFail();
            if (! $card->customer_id) {
                return null;
            }

            $customer = User::customers()->whereKey($card->customer_id)->lockForUpdate()->firstOrFail();
            $old = ['loyalty_number' => $customer->loyalty_number, 'loyalty_expires_at' => $customer->loyalty_expires_at?->toDateString()];
            if ($customer->loyalty_number === $card->number) {
                $customer->forceFill(['loyalty_number' => null])->save();
            }
            $card->update(['customer_id' => null]);

            return compact('card', 'customer', 'old');
        });

        if ($result === null) {
            return back()->with('error', 'This loyalty card is already available.');
        }

        $audit->log('customer.loyalty_number', $result['customer'], $result['old'], [
            'loyalty_number' => $result['customer']->fresh()->loyalty_number,
            'loyalty_expires_at' => $result['customer']->fresh()->loyalty_expires_at?->toDateString(),
        ]);
        $audit->log('loyalty_card.disconnected', $result['card'], ['customer_id' => $result['customer']->id], ['customer_id' => null]);

        return back()->with('success', LoyaltyNumber::format($result['card']->number).' disconnected from '.$result['customer']->name.' and returned to available cards.');
    }
}
