<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['appointment_id', 'service_id', 'service_variant_id', 'staff_profile_id', 'service_name', 'variant_name', 'quantity', 'unit_price', 'duration_minutes', 'subtotal', 'deposit_amount', 'product_cost'])]
class AppointmentItem extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'subtotal' => 'decimal:2',
            'product_cost' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id')->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id')->withTrashed();
    }
}
