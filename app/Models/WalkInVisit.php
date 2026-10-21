<?php

namespace App\Models;

use App\Models\Concerns\SeparatesTestData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['guest_name', 'phone', 'branch_id', 'staff_profile_id', 'created_by', 'visited_at', 'status', 'completed_at', 'total_amount', 'notes'])]
class WalkInVisit extends Model
{
    use HasUlids, SeparatesTestData;

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_test' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(WalkInVisitItem::class);
    }
}
