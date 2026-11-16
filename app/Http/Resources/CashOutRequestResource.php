<?php

namespace App\Http\Resources;

use App\Enums\CashOutStatus;
use App\Models\CashOutRequest;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A cash-out request as the customer sees it. @mixin CashOutRequest */
class CashOutRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'gcash_number' => $this->gcash_number,
            'gcash_name' => $this->gcash_name,
            'status' => $this->status->value,
            'status_label' => $this->status->customerLabel(),
            'reference_number' => $this->status === CashOutStatus::Completed ? $this->reference_number : null,
            'admin_note' => $this->admin_note,
            'created_at' => Fmt::iso($this->created_at),
            'processed_at' => Fmt::iso($this->processed_at),
        ];
    }
}
