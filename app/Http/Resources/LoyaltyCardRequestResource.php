<?php

namespace App\Http\Resources;

use App\Enums\LoyaltyCardRequestStatus;
use App\Models\LoyaltyCardRequest;
use App\Support\Fmt;
use App\Support\LoyaltyNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The requesting customer's view of their loyalty card request. @mixin LoyaltyCardRequest */
class LoyaltyCardRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->customerLabel(),
            'endorser_name' => LoyaltyCardRequest::shortName($this->endorser?->name),
            'gcash_amount' => (float) $this->gcash_amount,
            'endorser_cash' => (float) $this->endorser_cash,
            'reference_number' => $this->reference_number,
            // The number set aside is the admin's business until the card is actually linked.
            'card_number' => $this->status === LoyaltyCardRequestStatus::Approved ? LoyaltyNumber::format($this->loyaltyCard?->number) : null,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => Fmt::iso($this->created_at),
            'reviewed_at' => Fmt::iso($this->reviewed_at),
        ];
    }
}
