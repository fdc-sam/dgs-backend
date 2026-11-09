<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LoyaltyCardRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyCardRequest;
use App\Services\FileUploadService;
use App\Services\LoyaltyCardRequestService;
use App\Support\LoyaltyNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Loyalty cards → Requests: cards asked for through a friend, waiting for the GCash receipt to be checked. */
class LoyaltyCardRequestController extends Controller
{
    public function __construct(private readonly LoyaltyCardRequestService $requests) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(LoyaltyCardRequestStatus::values())],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = LoyaltyCardRequestStatus::from($data['status'] ?? LoyaltyCardRequestStatus::Pending->value);

        $items = LoyaltyCardRequest::query()->with(['customer', 'endorser', 'loyaltyCard'])
            ->where('status', $status->value)
            ->when($data['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = LoyaltyNumber::normalize($term);
                $q->where(fn ($q) => $q->whereLike('reference_number', $like)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)->orWhereLike('email', $like))
                    ->orWhereHas('endorser', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like))
                    ->when(is_string($digits) && ctype_digit($digits), fn ($q) => $q->orWhereHas('loyaltyCard', fn ($c) => $c->where('number', $digits))));
            })
            // Oldest receipt first so nobody waits too long; history newest first.
            ->when($status === LoyaltyCardRequestStatus::Pending, fn ($q) => $q->orderBy('created_at'), fn ($q) => $q->orderByDesc('reviewed_at'))
            ->paginate(self::PER_PAGE)->withQueryString();

        $counts = LoyaltyCardRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.loyalty-cards.requests.index', [
            'items' => $items,
            'status' => $status,
            'counts' => $counts,
            'enabled' => $this->requests->isEnabled(),
        ]);
    }

    public function show(LoyaltyCardRequest $cardRequest): View
    {
        $cardRequest->load(['customer', 'endorser', 'loyaltyCard', 'reviewer']);
        $endorser = $cardRequest->endorser;

        return view('admin.loyalty-cards.requests.show', [
            'r' => $cardRequest,
            'endorserStats' => [
                'approved' => LoyaltyCardRequest::query()->where('endorser_id', $endorser?->id)->where('status', LoyaltyCardRequestStatus::Approved->value)->count(),
                'earned' => (float) LoyaltyCardRequest::query()->where('endorser_id', $endorser?->id)->where('status', LoyaltyCardRequestStatus::Approved->value)->sum('endorser_cash'),
            ],
        ]);
    }

    public function approve(LoyaltyCardRequest $cardRequest, Request $request): RedirectResponse
    {
        $approved = $this->requests->approve($cardRequest, $request->user());

        return redirect()->route('loyalty-cards.requests.show', $approved)->with('success',
            'Approved. Loyalty card '.LoyaltyNumber::format($approved->loyaltyCard?->number).' is now active in '.$approved->customer?->name."'s app. It expires on "
            .$approved->customer?->loyalty_expires_at?->format('M j, Y').'.');
    }

    public function reject(LoyaltyCardRequest $cardRequest, Request $request): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->requests->reject($cardRequest, $request->user(), $data['reason']);

        return redirect()->route('loyalty-cards.requests.show', $cardRequest)->with('success', 'Rejected. The customer sees your reason in the app and can send a new receipt.');
    }

    /** The GCash receipt, streamed from the private disk to managers only. */
    public function proof(LoyaltyCardRequest $cardRequest): StreamedResponse
    {
        abort_unless($cardRequest->proof_path && Storage::disk(FileUploadService::privateDisk())->exists($cardRequest->proof_path), 404);

        return Storage::disk(FileUploadService::privateDisk())->response($cardRequest->proof_path, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
