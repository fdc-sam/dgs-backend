<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ReferralStatus;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\ReviewImage;
use App\Models\User;
use App\Support\TestMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A customer deleting their own account, in the app or on the website's /delete-account page (Google Play requires both).
 *
 * Personal data is removed: profile details, photo, favorites, reviews (with their images), referrals not yet rewarded,
 * notifications, push and API tokens, and the name, email, phone and QR code on the user row. Past appointments, payments
 * and paid referral rewards stay for the salon's accounting, linked to the anonymised, soft-deleted user. Upcoming appointments are cancelled first so their slots open
 * up and the team is told. The email and phone are freed, so the person can register again later.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly FileUploadService $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /** The website flow: the customer signs in with email and password on the deletion page. */
    public function deleteByCredentials(string $email, string $password): void
    {
        $user = User::where('email', strtolower($email))->first();

        // One message for every failure, so the page does not reveal which emails have accounts.
        if (! $user || ! $user->isCustomer() || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $this->delete($user);
    }

    /** The in-app flow: the customer is signed in and confirms with their password. */
    public function deleteWithPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }

        $this->delete($user);
    }

    public function delete(User $user): void
    {
        // On the customer's own side: a test customer deleting through the (live) website still finds their bookings.
        TestMode::on((bool) $user->is_test, fn () => $this->deleteOnSide($user));
    }

    private function deleteOnSide(User $user): void
    {
        $upcoming = [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Rescheduled->value];
        $user->appointments()->whereIn('status', $upcoming)->get()->each(
            fn (Appointment $appointment) => $this->appointments->cancel($appointment, $user, 'Customer deleted their account.', enforceCustomerRules: false),
        );

        $files = [
            $user->profile_photo,
            ...ReviewImage::query()->whereIn('review_id', $user->reviews()->withTrashed()->select('id'))->pluck('path')->all(),
        ];
        $email = $user->email;

        DB::transaction(function () use ($user, $email) {
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->favorites()->delete();
            $user->referrals()->where('status', '!=', ReferralStatus::Rewarded->value)->delete();
            $user->reviews()->withTrashed()->forceDelete();
            $user->notifications()->delete();
            $user->customerProfile()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $user->forceFill([
                'name' => 'Deleted customer',
                'email' => "deleted-{$user->id}@deleted.invalid",
                'email_verified_at' => null,
                'phone' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'profile_photo' => null,
                'qr_code' => null,
                'status' => UserStatus::Inactive,
            ])->save();
            $user->delete();
        });

        foreach (array_filter($files) as $path) {
            $this->uploads->delete($path);
        }
        $this->audit->log('customer.deleted', $user);
    }
}
