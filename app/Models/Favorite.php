<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A user's saved service, staff member or promotion (polymorphic). */
#[Fillable(['user_id', 'favoritable_type', 'favoritable_id'])]
class Favorite extends Model
{
    use HasUlids;

    /** API "type" value => morph alias. */
    public const TYPES = ['service', 'staff', 'promotion'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
