<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly FileUploadService $uploads) {}

    /** @return array{user: User, token: string} */
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => UserRole::Customer,
                'status' => UserStatus::Active,
            ]);
            $user->customerProfile()->create([]);

            return $user;
        });

        event(new Registered($user));

        return ['user' => $user->load('customerProfile'), 'token' => $this->issueToken($user, $data['device_name'] ?? null)];
    }

    /** @return array{user: User, token: string} */
    public function login(string $email, string $password, ?string $deviceName): array
    {
        $user = User::where('email', strtolower($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->isActive()) {
            throw ApiException::forbidden('Your account is '.$user->status->value.'. Please contact the salon.');
        }
        if (! $user->isCustomer()) {
            throw ApiException::forbidden('This app is for customers. Staff and administrators should use the admin portal.');
        }

        return ['user' => $user->load('customerProfile'), 'token' => $this->issueToken($user, $deviceName)];
    }

    /** Sign-in for the admin mobile app: admins, super admins and staff only. @return array{user: User, token: string} */
    public function loginAdminApp(string $email, string $password, ?string $deviceName): array
    {
        $user = User::where('email', strtolower($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->isActive()) {
            throw ApiException::forbidden('Your account is '.$user->status->value.'. Please contact the owner.');
        }
        if (! $user->role->canAccessAdmin()) {
            throw ApiException::forbidden('This app is for DGS staff. Customers should use the DGS Salon app.');
        }

        return ['user' => $user->load('staffProfile.branches'), 'token' => $user->createToken($deviceName ?: 'admin-app', ['admin-app'], now()->addDays(30))->plainTextToken];
    }

    public function logout(User $user, ?string $deviceToken): void
    {
        if ($deviceToken) {
            $user->deviceTokens()->where('token', $deviceToken)->delete();
        }
        $user->currentAccessToken()?->delete();
    }

    public function updateProfile(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data) {
            $userFields = array_intersect_key($data, array_flip(['name', 'email', 'phone']));
            if (isset($userFields['email'])) {
                $userFields['email'] = strtolower($userFields['email']);
                if ($userFields['email'] !== $user->email) {
                    $userFields['email_verified_at'] = null;
                }
            }
            $user->update($userFields);

            $profileFields = array_intersect_key($data, array_flip(['date_of_birth', 'gender', 'address', 'emergency_contact_name', 'emergency_contact_phone']));
            if ($profileFields) {
                $user->customerProfile()->updateOrCreate(['user_id' => $user->id], $profileFields);
            }
        });

        return $user->fresh('customerProfile');
    }

    public function updatePhoto(User $user, UploadedFile $photo): User
    {
        $user->update(['profile_photo' => $this->uploads->storeImage($photo, 'customers', $user->profile_photo)]);

        return $user->fresh('customerProfile');
    }

    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->update(['password' => $new]);
        // Sign out other devices; keep the current session.
        $user->tokens()->whereKeyNot($user->currentAccessToken()?->id)->delete();
    }

    /** Always succeeds from the caller's perspective so account existence is not revealed. */
    public function sendResetCode(string $email): void
    {
        $user = User::where('email', strtolower($email))->first();
        if (! $user || ! $user->isActive()) {
            return;
        }

        // 6-digit code stored hashed where the password broker expects its token, so broker->reset() validates it.
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );
        RateLimiter::clear($this->resetAttemptsKey($user->email));

        $user->notify(new ResetPasswordCode($code));
    }

    public function resetPassword(array $data): void
    {
        $attemptsKey = $this->resetAttemptsKey($data['email']);
        if (RateLimiter::tooManyAttempts($attemptsKey, 5)) {
            DB::table('password_reset_tokens')->where('email', strtolower($data['email']))->delete();
            throw ValidationException::withMessages(['token' => 'Too many incorrect attempts. Please request a new reset code.']);
        }

        $status = Password::broker()->reset(
            [...$data, 'email' => strtolower($data['email'])],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            RateLimiter::hit($attemptsKey, 3600);
            throw ValidationException::withMessages(['token' => 'This reset code is invalid or has expired.']);
        }
    }

    private function resetAttemptsKey(string $email): string
    {
        return 'password-reset:'.sha1(strtolower($email));
    }

    private function issueToken(User $user, ?string $deviceName): string
    {
        return $user->createToken($deviceName ?: 'mobile', ['customer'], now()->addDays(60))->plainTextToken;
    }
}
