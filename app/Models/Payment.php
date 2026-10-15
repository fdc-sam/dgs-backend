<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A deposit receipt for an appointment (see App\Services\PaymentService). */
#[Fillable([
    'appointment_id', 'customer_id', 'method', 'amount', 'amount_received', 'reference_number', 'proof_path',
    'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
])]
class Payment extends Model
{
    use HasUlids;

    public const METHOD_GCASH = 'gcash';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $attributes = ['method' => self::METHOD_GCASH, 'status' => self::SUBMITTED];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::SUBMITTED;
    }
}
