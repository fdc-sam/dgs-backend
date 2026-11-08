<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\CustomerCode;
use App\Support\TestMode;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

#[Fillable(['name', 'email', 'password', 'phone', 'role', 'profile_photo', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $attributes = [
        'role' => 'customer',
        'status' => 'active',
        'is_test' => false,
        // Set on new models too, so a just-registered customer's profile can show "no card yet".
        'loyalty_number' => null,
        'loyalty_expires_at' => null,
    ];

    protected static function booted(): void
    {
        // Every customer gets their unique QR code at the moment they are created, whichever way that happens
        // (app registration, admin, seeder, factory). It is not mass-assignable on purpose.
        static::creating(function (User $user) {
            if ($user->role === UserRole::Customer && blank($user->qr_code)) {
                $user->qr_code = static::newUniqueQrCode();
            }
        });

        // A loyalty card is good for a year from the day it is linked: a new (or different) number starts a fresh
        // year, saving the same number keeps it, and removing the card clears it. Renewing sets the date itself.
        static::saving(function (User $user) {
            if ($user->isDirty('loyalty_number') && ! $user->isDirty('loyalty_expires_at')) {
                $user->loyalty_expires_at = $user->loyalty_number === null ? null : static::loyaltyTermFrom(today());
            }
        });
    }

    public static function newUniqueQrCode(): string
    {
        do {
            $code = CustomerCode::generate();
        } while (static::withTrashed()->where('qr_code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'is_test' => 'boolean',
            // The day the loyalty card stops working (it is good up to the day before).
            'loyalty_expires_at' => 'immutable_date',
        ];
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'customer_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'customer_id');
    }

    /** People this customer referred to the salon. */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /** The salon has linked a loyalty card to this customer, whether or not it is still valid. */
    public function hasLoyaltyCard(): bool
    {
        return filled($this->loyalty_number);
    }

    /** The card's year has run out: no card perks or loyalty prices until the salon renews it. */
    public function loyaltyCardExpired(): bool
    {
        return $this->hasLoyaltyCard() && $this->loyalty_expires_at !== null && ! today()->lt($this->loyalty_expires_at);
    }

    /** A linked card that has not expired: the customer gets the loyalty prices. */
    public function hasActiveLoyaltyCard(): bool
    {
        return $this->hasLoyaltyCard() && ! $this->loyaltyCardExpired();
    }

    /** Whole days until the card expires (0 once it has), or null without a card or an expiry date. */
    public function loyaltyCardDaysLeft(): ?int
    {
        if (! $this->hasLoyaltyCard() || $this->loyalty_expires_at === null) {
            return null;
        }

        return max(0, (int) today()->diffInDays($this->loyalty_expires_at, false));
    }

    /** The admin can renew a card once it has expired, or in the last few weeks before (config salon.loyalty). */
    public function loyaltyCardRenewable(): bool
    {
        $daysLeft = $this->loyaltyCardDaysLeft();

        return $daysLeft !== null && $daysLeft <= (int) config('salon.loyalty.renewal_window_days');
    }

    /**
     * Another year on the card: from today when it has expired, or from its current expiry date when renewed early,
     * so no days are lost. Saves the customer.
     */
    public function renewLoyaltyCard(): void
    {
        $from = $this->loyaltyCardExpired() || $this->loyalty_expires_at === null ? today() : $this->loyalty_expires_at;
        $this->forceFill(['loyalty_expires_at' => static::loyaltyTermFrom($from)])->save();
    }

    private static function loyaltyTermFrom(DateTimeInterface $from): string
    {
        return Carbon::parse($from)->startOfDay()->addMonthsNoOverflow((int) config('salon.loyalty.validity_months'))->toDateString();
    }

    /**
     * After a password change or reset: end every other API session (mobile apps) and stop push to the devices they were
     * on. The current API token and, when given, this device's push token are kept. Web sessions are signed out by the
     * `auth.session` middleware once the password hash changes.
     */
    public function signOutOtherDevices(?string $keepDeviceToken = null): void
    {
        $current = $this->currentAccessToken();
        $this->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
        $this->deviceTokens()->when($keepDeviceToken, fn ($q, $token) => $q->where('token', '!=', $token))->delete();
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** FCM tokens used by the notification channel. */
    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }

    /**
     * Move the account to the test side (it then sees, and is seen by, test accounts only) or back to live.
     * A customer's bookings and reviews move with them; walk-ins stay where the admin recorded them.
     */
    public function setTestAccount(bool $test): void
    {
        DB::transaction(function () use ($test) {
            $this->forceFill(['is_test' => $test])->save();
            if ($this->isCustomer()) {
                Appointment::withoutGlobalScopes()->where('customer_id', $this->id)->update(['is_test' => $test]);
                Review::withoutGlobalScopes()->where('customer_id', $this->id)->update(['is_test' => $test]);
                Referral::withoutGlobalScopes()->where('referrer_id', $this->id)->update(['is_test' => $test]);
                LoyaltyCard::withoutGlobalScopes()->where('customer_id', $this->id)->update(['is_test' => $test]);
            }
        });
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isManager(): bool
    {
        return $this->role->isManager();
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function profilePhotoUrl(): ?string
    {
        return $this->profile_photo ? Storage::disk(\App\Services\FileUploadService::publicDisk())->url($this->profile_photo) : null;
    }

    public function scopeRole(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    /** Customers on the current test/live side (see TestMode); console code sees all of them. */
    public function scopeCustomers(Builder $query): Builder
    {
        $side = TestMode::side();

        return $query->where('role', UserRole::Customer->value)
            ->when($side !== null, fn ($q) => $q->where($q->qualifyColumn('is_test'), $side));
    }

    /** Users who manage bookings and receive new-booking alerts. */
    public function scopeManagers(Builder $query): Builder
    {
        return $query->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Admin->value])
            ->where('status', UserStatus::Active->value);
    }
}
