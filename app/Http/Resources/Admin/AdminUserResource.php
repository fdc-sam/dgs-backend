<?php

namespace App\Http\Resources\Admin;

use App\Models\Branch;
use App\Models\User;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in admin / staff member, with the branches they work at and what they are allowed to do. */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $manager = $user->isManager();
        $profile = $user->staffProfile;

        // Managers work across every active branch; staff at the branches they are assigned to.
        $branches = $manager
            ? Branch::query()->active()->orderBy('name')->get()
            : ($profile?->branches ?? collect());

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'position' => $profile?->position,
            'profile_photo_url' => Fmt::url($user->profile_photo),
            'staff_profile_id' => $profile?->id,
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address])->values(),
            'permissions' => [
                // Managers see every appointment and customer and can book walk-ins; staff only their own appointments.
                'view_all_appointments' => $manager,
                'view_customer_details' => $manager,
                'create_walk_in' => $manager,
                'cancel_appointments' => $manager,
            ],
        ];
    }
}
