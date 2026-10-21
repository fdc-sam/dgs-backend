<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Models\Concerns\SeparatesTestData;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['appointment_id', 'customer_id', 'service_id', 'rating', 'review', 'status'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, HasUlids, SeparatesTestData, SoftDeletes;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    /** A customer's booking is on their side, whoever makes it (the app, or the admin app at the counter). */
    protected function testFlagForNewRecord(): bool
    {
        return (bool) User::withTrashed()->whereKey($this->customer_id)->value('is_test');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved->value);
    }
}
