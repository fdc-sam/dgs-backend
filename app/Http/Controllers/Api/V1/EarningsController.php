<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CreateCashOutRequest;
use App\Http\Resources\CashOutRequestResource;
use App\Models\CashOutRequest;
use App\Services\EarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's earnings (referral and card endorsement rewards), and their requests to cash them out to GCash. */
class EarningsController extends ApiController
{
    public function __construct(private readonly EarningsService $earnings) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $cashOuts = CashOutRequest::query()->where('customer_id', $user->id)->latest()->orderByDesc('id')->limit(100)->get();

        return $this->ok([
            ...$this->earnings->summary($user),
            'min_amount' => (float) EarningsService::MIN_AMOUNT,
            // Suggested for the cash-out form; the customer can send it elsewhere.
            'gcash_number' => $user->phone,
            'gcash_name' => $user->name,
            'cash_outs' => CashOutRequestResource::collection($cashOuts),
        ]);
    }

    public function store(CreateCashOutRequest $request): JsonResponse
    {
        $cashOut = $this->earnings->request($request->user(), (float) $request->input('amount'), $request->input('gcash_number'), $request->input('gcash_name'));

        return $this->created(new CashOutRequestResource($cashOut),
            "Cash-out request sent! We'll notify you once it's in your GCash.");
    }

    public function destroy(Request $request, CashOutRequest $cashOut): JsonResponse
    {
        abort_unless($cashOut->customer_id === $request->user()->id, 404);
        if (! $cashOut->isPending()) {
            throw ApiException::conflict('This cash-out request was already '.strtolower($cashOut->status->customerLabel()).'.');
        }
        $this->earnings->cancel($cashOut);

        return $this->ok(new CashOutRequestResource($cashOut), 'Cash-out request cancelled.');
    }
}
