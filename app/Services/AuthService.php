<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
use App\Support\AdminLoginIdentifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly FileUploadService $uploads,
        private readonly GoogleIdTokenVerifier $google,
    ) {}

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
        app(InviteService::class)->attachOnSignup($user, $data['invite_code'] ?? null);

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

    /**
     * Sign in with Google (customer app). Signs in the customer with that Google account, or with its (Google-verified)
     * email, linking the two; otherwise creates the customer, which, like the sign-up form, needs a mobile number.
     *
     * @param  array{id_token: string, phone?: ?string, device_name?: ?string, invite_code?: ?string}  $data
     * @return array{user: User, token: string, created: bool}|array{needs_phone: true, name: string, email: string}
     */
    public function loginWithGoogle(array $data): array
    {
        $claims = $this->google->verify($data['id_token']);
        if (! $claims) {
            throw ValidationException::withMessages(['id_token' => 'Google sign-in failed. Please try again.']);
        }
        $email = strtolower((string) ($claims['email'] ?? ''));
        $emailVerified = $email !== '' && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $emailVerified) {
            throw ValidationException::withMessages(['id_token' => 'Your Google account has no verified email address.']);
        }

        $user = User::where('google_id', $claims['sub'])->first() ?? User::where('email', $email)->first();
        if ($user) {
            if ($user->google_id && $user->google_id !== $claims['sub']) {
                throw ApiException::forbidden('This email is linked to a different Google account. Please sign in with your password.');
            }
            if (! $user->isActive()) {
                throw ApiException::forbidden('Your account is '.$user->status->value.'. Please contact the salon.');
            }
            if (! $user->isCustomer()) {
                throw ApiException::forbidden('This app is for customers. Staff and administrators should use the admin portal.');
            }
            $user->forceFill(['google_id' => $claims['sub'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return ['user' => $user->load('customerProfile'), 'token' => $this->issueToken($user, $data['device_name'] ?? null), 'created' => false];
        }

        $name = trim((string) ($claims['name'] ?? '')) ?: Str::before($email, '@');
        if (blank($data['phone'] ?? null)) {
            return ['needs_phone' => true, 'name' => $name, 'email' => $email];
        }

        $user = DB::transaction(function () use ($data, $claims, $email, $name) {
            $user = User::create([
                'name' => Str::limit($name, 120, ''),
                'email' => $email,
                'phone' => $data['phone'],
                'password' => null,
                'role' => UserRole::Customer,
                'status' => UserStatus::Active,
            ]);
            $user->forceFill(['google_id' => $claims['sub'], 'email_verified_at' => now()])->save();
            $user->customerProfile()->create([]);

            return $user;
        });

        event(new Registered($user));
        app(InviteService::class)->attachOnSignup($user, $data['invite_code'] ?? null);

        return ['user' => $user->load('customerProfile'), 'token' => $this->issueToken($user, $data['device_name'] ?? null), 'created' => true];
    }

    /** Sign-in for the admin mobile app: admins, super admins and staff only. @return array{user: User, token: string} */
    public function loginAdminApp(string $email, string $password, ?string $deviceName): array
    {
        $user = User::where('email', AdminLoginIdentifier::resolve($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->isActive()) {
            throw ApiException::forbidden('Your account is '.$user->status->value.'. Please contact the owner.');
        }
        if (! $user->role->canAccessAdmin() || $user->isCashier()) {
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

    /** @param  ?string  $deviceToken  this device's push token, kept so it still gets notifications */
    public function changePassword(User $user, ?string $current, string $new, ?string $deviceToken = null): void
    {
        // A customer who only signed in with Google has no password yet: they set their first one without a current one.
        if ($user->password !== null && ! Hash::check((string) $current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->update(['password' => $new]);
        // Sign out other devices (and stop their push); keep the current session.
        $user->signOutOtherDevices($deviceToken);
    }

    /** Always succeeds from the caller's perspective so account existence is not revealed. */
    public function sendResetCode(string $email): void
    {
        $user = User::where('email', strtolower($email))->first();
        if (! $user || ! $user->isActive()) {
            return;
        }
        // At most 3 codes an hour per account, so the inbox cannot be flooded and fresh codes do not buy fresh guesses.
        $sendKey = 'password-reset-send:'.sha1($user->email);
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return;
        }
        RateLimiter::hit($sendKey, 3600);

        // 6-digit code stored hashed where the password broker expects its token, so broker->reset() validates it.
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        $user->notify(new ResetPasswordCode($code));
    }

    public function resetPassword(array $data): void
    {
        // 5 guesses an hour per account, across codes. Counted before checking, so parallel guesses cannot slip past it.
        $attemptsKey = $this->resetAttemptsKey($data['email']);
        if (RateLimiter::hit($attemptsKey, 3600) > 5) {
            DB::table('password_reset_tokens')->where('email', strtolower($data['email']))->delete();
            throw ValidationException::withMessages(['token' => 'Too many incorrect attempts. Please wait an hour, then request a new reset code.']);
        }

        $status = Password::broker()->reset(
            [...$data, 'email' => strtolower($data['email'])],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->signOutOtherDevices();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset code is invalid or has expired.']);
        }
        RateLimiter::clear($attemptsKey);
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
