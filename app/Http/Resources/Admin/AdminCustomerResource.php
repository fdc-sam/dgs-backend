<?php

namespace App\Http\Resources\Admin;

use App\Enums\AppointmentStatus;
use App\Models\User;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as seen by the admin app. Managers get the full record; staff (stylists) only see who the customer is,
 * because they are there to serve their own appointment, not to browse the client list.
 */
class AdminCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $customer */
        $customer = $this->resource;
        $manager = (bool) $request->user()?->isManager();
        $profile = $customer->customerProfile;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'status' => $customer->status->value,
            'profile_photo_url' => Fmt::url($customer->profile_photo),
            'member_since' => Fmt::iso($customer->created_at),
            'limited' => ! $manager,
            'email' => $this->when($manager, $customer->email),
            'phone' => $this->when($manager, $customer->phone),
            'qr_code' => $this->when($manager, $customer->qr_code),
            'loyalty_number' => $this->when($manager, $customer->loyalty_number),
            'birthday' => $this->when($manager, $profile?->date_of_birth?->toDateString()),
            'internal_notes' => $this->when($manager, $profile?->notes),
            'stats' => $this->when($manager, fn () => $this->stats($customer)),
        ];
    }

    private function stats(User $customer): array
    {
        $appointments = $customer->appointments();
        $count = fn (AppointmentStatus $s) => (clone $appointments)->where('status', $s->value)->count();
        $lastVisit = (clone $appointments)->where('status', AppointmentStatus::Completed->value)->max('starts_at');

        return [
            'total' => (clone $appointments)->count(),
            'completed' => $count(AppointmentStatus::Completed),
            'cancelled' => $count(AppointmentStatus::Cancelled),
            'no_show' => $count(AppointmentStatus::NoShow),
            'last_visit' => $lastVisit ? Fmt::iso(CarbonImmutable::parse($lastVisit)) : null,
            'lifetime_value' => Fmt::money((clone $appointments)->where('status', AppointmentStatus::Completed->value)->sum('total_amount')),
        ];
    }
}
