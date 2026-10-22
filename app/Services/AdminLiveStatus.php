<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReferralStatus;
use App\Models\Appointment;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The small snapshot open admin pages poll (GET /live) to keep their badges current and to notice new data:
 * badge counts, plus a marker per list that changes whenever a row on it is added, edited or removed.
 * Only what the viewer may see, on their test/live side (the models' global scopes).
 */
class AdminLiveStatus
{
    /** Lists a page can watch for changes. */
    public const WATCHABLE = ['appointments', 'referrals'];

    /** @return array{unread: int, new_referrals: int, payments_to_verify: int, markers: array<string, ?string>} */
    public function snapshot(User $user): array
    {
        $manager = $user->isManager();

        return [
            'unread' => $user->unreadNotifications()->count(),
            'new_referrals' => $manager ? Referral::query()->where('status', ReferralStatus::Pending->value)->count() : 0,
            'payments_to_verify' => $manager ? Appointment::query()->where('payment_status', PaymentStatus::Submitted->value)->count() : 0,
            'markers' => array_combine(self::WATCHABLE, array_map(fn ($list) => $this->marker($user, $list), self::WATCHABLE)),
        ];
    }

    /** "rows|last change" for a watched list; null when the viewer cannot see that list. */
    public function marker(User $user, string $list): ?string
    {
        $query = match ($list) {
            'appointments' => $this->appointmentsFor($user),
            'referrals' => $user->isManager() ? Referral::query() : null,
            default => null,
        };
        if ($query === null) {
            return null;
        }

        $row = $query->toBase()->selectRaw('COUNT(*) AS total, MAX(updated_at) AS changed')->first();

        return $row->total.'|'.$row->changed;
    }

    /** Managers see every booking; staff only the ones they work on (as on the Appointments page). */
    private function appointmentsFor(User $user): Builder
    {
        $staffId = $user->staffProfile?->id ?? '';

        return Appointment::query()->when($user->isStaff(), fn ($q) => $q->where(fn ($q) => $q->where('staff_profile_id', $staffId)
            ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $staffId))));
    }
}
