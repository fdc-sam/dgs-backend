<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * Customers: only their own appointments. Staff: appointments assigned to them.
 * Admins / super admins: everything.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isManager()
            || $appointment->isOwnedBy($user)
            || $this->isAssignedStaff($user, $appointment);
    }

    public function create(User $user): bool
    {
        return $user->isCustomer() && $user->isActive();
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->isManager() || ($user->isCustomer() && $appointment->isOwnedBy($user));
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->cancel($user, $appointment);
    }

    /** Upload a GCash receipt for the deposit. */
    public function pay(User $user, Appointment $appointment): bool
    {
        return $user->isCustomer() && $appointment->isOwnedBy($user);
    }

    /** Approve/reject receipts, waive or refund deposits, view receipt images. */
    public function reviewPayment(User $user, Appointment $appointment): bool
    {
        return $user->isManager() && $user->isActive();
    }

    /** Confirm / start / complete / no-show. */
    public function updateStatus(User $user, Appointment $appointment): bool
    {
        return $user->isManager() || $this->isAssignedStaff($user, $appointment);
    }

    /** The customer has arrived at the branch. */
    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $this->updateStatus($user, $appointment);
    }

    /** Book at the counter for a customer who is there (managers only). */
    public function createForCustomer(User $user): bool
    {
        return $user->isManager() && $user->isActive();
    }

    public function updateNotes(User $user, Appointment $appointment): bool
    {
        return $this->updateStatus($user, $appointment);
    }

    private function isAssignedStaff(User $user, Appointment $appointment): bool
    {
        if (! $user->isStaff() || ! $user->staffProfile?->id) {
            return false;
        }

        return $appointment->staff_profile_id === $user->staffProfile->id
            || $appointment->items()->where('staff_profile_id', $user->staffProfile->id)->exists();
    }
}
