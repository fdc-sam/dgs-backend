<?php

namespace App\Http\Resources;

use App\Models\LoyaltyCardRequest;
use App\Models\User;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->customerProfile;
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'profile_photo_url' => Fmt::url($this->profile_photo),
            // The number on the loyalty card the salon linked (digits only), or null.
            'loyalty_number' => $this->loyalty_number,
            // The day the card stops working (YYYY-MM-DD; it is good up to the day before), and whether that has come.
            'loyalty_expires_at' => $this->loyalty_expires_at?->toDateString(),
            'loyalty_expired' => $this->resource->loyaltyCardExpired(),
            // Invite a friend to DGS Membership: this customer's code (their link is on GET /invites), and the friend
            // they signed up with, whose invite their membership payment goes through without typing a code.
            'invite_code' => $this->invite_code,
            'invited_by' => $this->invited_by_id && ($inviter = $this->invitedBy) && ! $inviter->trashed()
                ? ['name' => LoyaltyCardRequest::shortName($inviter->name), 'code' => $inviter->invite_code]
                : null,
            'email_verified_at' => Fmt::iso($this->email_verified_at),
            // Sign in with Google: whether this account is linked to a Google account, and whether that is its only way in
            // (no password yet: change-password then sets the first one without the current one, and delete asks none).
            'google_linked' => $google = filled($attributes['google_id'] ?? null),
            'google_only' => $google && array_key_exists('password', $attributes) && $attributes['password'] === null,
            'profile' => $profile ? [
                'date_of_birth' => $profile->date_of_birth?->toDateString(),
                'gender' => $profile->gender,
                'address' => $profile->address,
                'emergency_contact_name' => $profile->emergency_contact_name,
                'emergency_contact_phone' => $profile->emergency_contact_phone,
            ] : null,
            'created_at' => Fmt::iso($this->created_at),
        ];
    }
}
