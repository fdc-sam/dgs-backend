<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\UserStatus;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(StaffFactory::class)]
#[Fillable(['user_id', 'position', 'bio', 'employee_code', 'hire_date', 'status'])]
class StaffProfile extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'hire_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_staff')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_staff')->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class)->orderBy('day_of_week');
    }

    public function daysOff(): HasMany
    {
        return $this->hasMany(StaffDayOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function favorites(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    /** Active profile whose login account is also active. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('staff_profiles.status', RecordStatus::Active->value)
            ->whereHas('user', fn (Builder $q) => $q->where('status', UserStatus::Active->value));
    }

    public function scopeAtBranch(Builder $query, string $branchId): Builder
    {
        return $query->whereHas('branches', fn (Builder $q) => $q->where('branches.id', $branchId));
    }

    public function scopeOffersService(Builder $query, string $serviceId): Builder
    {
        return $query->whereHas('services', fn (Builder $q) => $q->where('services.id', $serviceId));
    }

    public function displayName(): string
    {
        return $this->user?->name ?? '';
    }
}
