<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CashOutStatus;
use App\Http\Controllers\Controller;
use App\Models\CashOutRequest;
use App\Services\EarningsService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cash-outs: customers asking for their earnings (referral and card endorsement rewards) in GCash. The admin sends the
 * money to the GCash number shown, then enters the reference number; the customer is notified and it leaves their balance.
 */
class CashOutController extends Controller
{
    public function __construct(private readonly EarningsService $earnings) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...CashOutStatus::values(), 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['status'] ?? CashOutStatus::Pending->value;

        $items = CashOutRequest::query()->with(['customer', 'processor'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($data['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->whereKey($term)->orWhereLike('gcash_number', $like)->orWhereLike('gcash_name', $like)
                    ->orWhereLike('reference_number', $like)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)->orWhereLike('email', $like)));
            })
            // Oldest request first so nobody waits too long; history newest first.
            ->when($status === CashOutStatus::Pending->value, fn ($q) => $q->orderBy('created_at'), fn ($q) => $q->latest()->orderByDesc('id'))
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.cash-outs.index', [
            'items' => $items,
            'status' => $status,
            'counts' => CashOutRequest::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            // What each customer has left once this request is sent, to catch mistakes before sending.
            'balances' => $items->getCollection()->pluck('customer')->filter()->unique('id')
                ->mapWithKeys(fn ($customer) => [$customer->id => $this->earnings->summary($customer)['balance']]),
        ]);
    }

    public function complete(Request $request, CashOutRequest $cashOut): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\s\-]{4,40}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'reference.required' => 'Enter the GCash reference number of the money you sent.',
            'reference.regex' => 'Enter the reference number as shown in GCash (letters and numbers).',
        ]);
        $this->earnings->complete($cashOut, $request->user(), $data['reference'], $data['note'] ?? null);

        return back()->with('success', Fmt::peso($cashOut->amount)." marked as sent to {$cashOut->customer?->name}. They have been notified.");
    }

    public function reject(Request $request, CashOutRequest $cashOut): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the customer why it was not sent.']);
        $this->earnings->reject($cashOut, $request->user(), $data['reason']);

        return back()->with('success', "Cash-out rejected. {$cashOut->customer?->name} sees your reason and the amount stays in their earnings.");
    }
}
