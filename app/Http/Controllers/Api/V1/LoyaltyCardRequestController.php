<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\SubmitLoyaltyCardRequest;
use App\Http\Resources\LoyaltyCardRequestResource;
use App\Models\LoyaltyCardRequest;
use App\Services\LoyaltyCardRequestService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loyalty card through a friend, in the app: look up the friend behind a scanned QR, send the GCash receipt, follow the
 * request; and, for the friend, what endorsing others has earned them.
 */
class LoyaltyCardRequestController extends ApiController
{
    public function __construct(private readonly LoyaltyCardRequestService $requests) {}

    /** The customer's own requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        $items = LoyaltyCardRequest::query()->where('customer_id', $request->user()->id)
            ->with(['endorser', 'loyaltyCard'])->latest()->orderByDesc('id')->limit(20)->get();

        return $this->ok(LoyaltyCardRequestResource::collection($items));
    }

    /** Who a scanned code belongs to, before the customer pays: errors say why that friend can't endorse them. */
    public function endorser(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']], ['code.required' => "Scan your friend's QR code first."]);
        $endorser = $this->requests->endorserFor($request->user(), $data['code']);

        return $this->ok(['endorser' => ['name' => LoyaltyCardRequest::shortName($endorser->name), 'code' => $endorser->qr_code]]);
    }

    public function store(SubmitLoyaltyCardRequest $request): JsonResponse
    {
        $created = $this->requests->submit($request->user(), $request->input('code'), $request->input('reference_number'), $request->file('proof'));

        return $this->created(new LoyaltyCardRequestResource($created->load(['endorser', 'loyaltyCard'])),
            "Request sent! We'll activate your card once we've checked your GCash payment.");
    }

    /** The endorser's side: cash earned from friends' approved cards, what is still waiting, and the full log. */
    public function endorsements(Request $request): JsonResponse
    {
        $data = $this->requests->endorsements($request->user());

        return $this->ok([
            'can_endorse' => $data['can_endorse'],
            'endorser_cash' => $data['endorser_cash'],
            'summary' => $data['summary'],
            'items' => $data['items']->map(fn (LoyaltyCardRequest $r) => [
                'id' => $r->id,
                'friend_name' => $r->customer?->name,
                'status' => $r->status->value,
                'status_label' => $r->status->customerLabel(),
                'amount' => (float) $r->endorser_cash,
                'created_at' => Fmt::iso($r->created_at),
                'reviewed_at' => Fmt::iso($r->reviewed_at),
            ])->values(),
        ]);
    }
}
