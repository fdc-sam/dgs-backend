<?php

namespace App\Http\Resources;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Services\PaymentService;
use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rules = app(AppointmentService::class);
        $isStaffView = $request->user()?->role?->canAccessAdmin() ?? false;
        $isManagerView = $request->user()?->isManager() ?? false;

        return [
            'id' => $this->id,
            'appointment_number' => $this->appointment_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'appointment_date' => $this->appointment_date->toDateString(),
            'start_time' => Fmt::time($this->start_time),
            'end_time' => Fmt::time($this->end_time),
            'starts_at' => Fmt::iso($this->starts_at),
            'duration_minutes' => $this->durationMinutes(),
            'subtotal' => Fmt::money($this->subtotal),
            'discount_amount' => Fmt::money($this->discount_amount),
            'additional_charge' => Fmt::money($this->additional_charge),
            'additional_charge_reason' => $this->additional_charge_reason,
            'total_amount' => Fmt::money($this->total_amount),
            'payment' => $this->payment(),
            'customer_notes' => $this->customer_notes,
            'admin_notes' => $this->when($isStaffView, $this->admin_notes),
            'customer' => $this->when($isStaffView, fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                // Contact details are for managers; stylists see who the customer is, not how to reach them.
                'email' => $this->when($isManagerView, $this->customer->email),
                'phone' => $this->when($isManagerView, $this->customer->phone),
            ]),
            'branch' => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'address' => $this->branch->address,
                'phone' => $this->branch->phone,
            ],
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->user?->name,
                'position' => $this->staff->position,
                'photo_url' => Fmt::url($this->staff->user?->profile_photo),
            ] : null,
            'promotion' => $this->promotion ? ['id' => $this->promotion->id, 'name' => $this->promotion->name] : null,
            'items' => AppointmentItemResource::collection($this->items),
            'can_cancel' => $rules->canCustomerCancel($this->resource),
            'can_reschedule' => $rules->canCustomerReschedule($this->resource),
            'can_review' => $rules->canBeReviewed($this->resource),
            'review' => $this->review ? new ReviewResource($this->review) : null,
            'source' => $this->source,
            'checked_in_at' => Fmt::iso($this->checked_in_at),
            'actions' => $this->when($isStaffView, fn () => $this->actionsFor($request)),
            'confirmed_at' => Fmt::iso($this->confirmed_at),
            'cancelled_at' => Fmt::iso($this->cancelled_at),
            'cancellation_reason' => $this->cancellation_reason,
            'completed_at' => Fmt::iso($this->completed_at),
            'created_at' => Fmt::iso($this->created_at),
        ];
    }

    /** GCash deposit state: drives the customer's payment screen. See docs/PAYMENTS.md. */
    private function payment(): array
    {
        $status = $this->payment_status;
        $latest = $this->latestPayment;
        $payments = app(PaymentService::class);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'method' => $status === PaymentStatus::NotRequired ? null : ($latest?->method ?? 'gcash'),
            'deposit_amount' => Fmt::money($this->deposit_amount),
            'amount_paid' => Fmt::money($this->amount_paid),
            'balance_due' => Fmt::money($this->resource->balanceDue()),
            // Only while the slot is actually on hold; staff confirming the booking stops the clock.
            'due_at' => Fmt::iso($status->isHolding() && $this->status->isActive() ? $this->payment_due_at : null),
            'paid_at' => Fmt::iso($this->paid_at),
            'can_pay' => $payments->canSubmit($this->resource),
            // What to scan: each service's own fixed-amount QR, plus the salon QR for any salon-wide deposit.
            'qr_codes' => $payments->qrCodesFor($this->resource),
            'reference_number' => $latest?->reference_number,
            'submitted_at' => Fmt::iso($latest?->created_at),
            'rejection_reason' => $status === PaymentStatus::Rejected ? $latest?->rejection_reason : null,
        ];
    }

    /**
     * What the signed-in staff member can do with this appointment right now (drives the admin app's buttons).
     *
     * @return list<string>
     */
    private function actionsFor(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        $can = fn (string $ability) => Gate::forUser($user)->allows($ability, $this->resource);
        $actions = [];

        if ($can('updateStatus')) {
            if ($status->isActive() && ! $this->checked_in_at) {
                $actions[] = 'check_in';
            }
            if (in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Rescheduled], true)) {
                $actions[] = 'confirm';
            }
            if ($status->canTransitionTo(AppointmentStatus::InProgress)) {
                $actions[] = 'start';
            }
            if ($status->canTransitionTo(AppointmentStatus::Completed)) {
                $actions[] = 'complete';
            }
            if ($status->canTransitionTo(AppointmentStatus::NoShow)) {
                $actions[] = 'no_show';
            }
        }
        if ($status->canTransitionTo(AppointmentStatus::Cancelled) && $can('cancel')) {
            $actions[] = 'cancel';
        }
        if ($can('updateNotes')) {
            $actions[] = 'edit_notes';
        }

        return $actions;
    }
}
