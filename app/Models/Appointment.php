<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\SeparatesTestData;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'appointment_number', 'customer_id', 'branch_id', 'staff_profile_id', 'promotion_id',
    'appointment_date', 'start_time', 'end_time', 'buffer_minutes', 'starts_at', 'status',
    'subtotal', 'discount_amount', 'total_amount', 'customer_notes', 'admin_notes',
    'confirmed_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'completed_at',
    'checked_in_at', 'checked_in_by', 'source',
    'payment_status', 'deposit_amount', 'amount_paid', 'payment_due_at', 'payment_reminded_at', 'paid_at',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUlids, SeparatesTestData;

    public const SOURCE_APP = 'app';

    public const SOURCE_WALK_IN = 'walk_in';

    protected $attributes = [
        'status' => 'pending', 'buffer_minutes' => 0, 'source' => self::SOURCE_APP,
        'payment_status' => 'not_required', 'deposit_amount' => 0, 'amount_paid' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'appointment_date' => 'date:Y-m-d',
            'is_test' => 'boolean',
            'starts_at' => 'datetime',
            'buffer_minutes' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'payment_status' => PaymentStatus::class,
            'deposit_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'payment_due_at' => 'datetime',
            'payment_reminded_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** A customer's booking is on their side, whoever makes it (the app, or the admin app at the counter). */
    protected function testFlagForNewRecord(): bool
    {
        return (bool) User::withTrashed()->whereKey($this->customer_id)->value('is_test');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id')->withTrashed();
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** The most recent receipt (drives the customer's payment screen and the staff review card). */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function scopeStatus(Builder $query, AppointmentStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::blockingValues());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Rescheduled->value, AppointmentStatus::InProgress->value]);
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->starts_at);
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->appointment_date->format('Y-m-d').' '.$this->end_time);
    }

    public function durationMinutes(): int
    {
        return (int) $this->startsAt()->diffInMinutes($this->endsAt());
    }

    /** What is left to pay at the salon after the deposit. */
    public function balanceDue(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->amount_paid, 2));
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->customer_id === $user->id;
    }
}
