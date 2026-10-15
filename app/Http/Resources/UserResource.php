<?php

namespace App\Http\Resources;

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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'profile_photo_url' => Fmt::url($this->profile_photo),
            'email_verified_at' => Fmt::iso($this->email_verified_at),
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
