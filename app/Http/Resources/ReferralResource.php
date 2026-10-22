<?php

namespace App\Http\Resources;

use App\Models\Referral;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Referral */
class ReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'services' => $this->services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The salon's note to the referrer, e.g. why a referral was not eligible.
            'status_note' => $this->admin_note,
            'reward_amount' => $this->reward_amount !== null ? (float) $this->reward_amount : null,
            'reward_reference' => $this->reward_reference,
            'rewarded_at' => Fmt::iso($this->rewarded_at),
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
            'created_at' => Fmt::iso($this->created_at),
        ];
    }
}
