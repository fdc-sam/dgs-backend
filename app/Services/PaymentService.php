<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Support\Booking\ServiceSelection;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Manual GCash deposits (no payment gateway). The customer books, pays a GCash QR, then uploads the receipt
 * with its reference number; staff check it against the GCash app and approve or reject it. Until then the
 * slot is held, and the scheduler releases it once payment_due_at passes.
 *
 * Where the deposit comes from: a service with its own QR (generated in GCash for a fixed downpayment, e.g. ₱50
 * for a ₱100 haircut) asks for exactly that amount. Services without one fall under the optional salon-wide
 * rule in Settings (any-amount salon QR + fixed/percent/full deposit).
 *
 * Every change runs on a row-locked appointment so a late upload, a staff decision and the expiry job
 * cannot overwrite each other. See docs/PAYMENTS.md for the full lifecycle.
 */
class PaymentService
{
    /** The customer gets one "your slot is on hold" nudge this many minutes before the deadline. */
    public const REMINDER_MINUTES = 10;

    /** Appointment statuses in which an unpaid deposit keeps the slot on hold. */
    private const HOLDING_STATUSES = [AppointmentStatus::Pending, AppointmentStatus::Rescheduled];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly FileUploadService $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /** The salon-wide deposit (for services without their own QR) is on and has somewhere to send money. */
    public function isEnabled(): bool
    {
        return $this->settings->bool('payment_gcash_enabled')
            && ($this->settings->get('payment_gcash_qr') || $this->settings->get('payment_gcash_number'));
    }

    /** Any booking can ask for a deposit: the salon-wide rule is on, or some service has its own QR. */
    public function isActive(): bool
    {
        return $this->isEnabled() || Service::query()->whereNotNull('gcash_qr')->where('deposit_amount', '>', 0)->exists();
    }

    /**
     * Deposit per selection and in total. A service's own QR amount is used exactly as encoded in the QR (never
     * capped, since the customer cannot pay a fixed-amount QR differently); the salon-wide rule covers the rest
     * and is capped so the deposit never exceeds the booking total.
     *
     * @param  Collection<int, ServiceSelection>  $selections
     * @return array{total: float, items: list<float>}
     */
    public function depositsFor(Collection $selections, float $total): array
    {
        $items = $selections->map(fn (ServiceSelection $s) => $s->service->ownDeposit() ?? 0.0)->values()->all();
        $own = array_sum($items);
        $restSubtotal = (float) $selections->filter(fn (ServiceSelection $s) => $s->service->ownDeposit() === null)
            ->sum(fn (ServiceSelection $s) => $s->unitPrice);
        $rest = $restSubtotal > 0 ? min($this->depositFor($restSubtotal), max(0, $total - $own)) : 0.0;

        return ['total' => round($own + $rest, 2), 'items' => $items];
    }

    /** Salon-wide deposit for an amount, per the admin's rule; 0 means nothing is collected in advance. */
    public function depositFor(float $total): float
    {
        if (! $this->isEnabled() || $total <= 0) {
            return 0.0;
        }

        $value = max(0, $this->settings->int('payment_deposit_value'));
        $deposit = match ($this->settings->get('payment_deposit_type')) {
            'full' => $total,
            'percent' => ceil($total * min($value, 100) / 100),
            default => (float) $value,
        };

        return round(min($deposit, $total), 2);
    }

    public function windowMinutes(): int
    {
        return max(5, $this->settings->int('payment_window_minutes', 30));
    }

    /** The payment window from now, but never later than the appointment itself. */
    public function dueAt(CarbonImmutable $startsAt): CarbonImmutable
    {
        return now()->addMinutes($this->windowMinutes())->min($startsAt);
    }

    /**
     * Payment columns for a booking the customer makes in the app, plus each item's own downpayment.
     *
     * @param  Collection<int, ServiceSelection>  $selections
     * @return array{0: array<string, mixed>, 1: list<float>}
     */
    public function forNewBooking(Collection $selections, float $total, CarbonImmutable $startsAt): array
    {
        ['total' => $deposit, 'items' => $items] = $this->depositsFor($selections, $total);
        if ($deposit <= 0) {
            return [['payment_status' => PaymentStatus::NotRequired, 'deposit_amount' => 0], $items];
        }

        return [[
            'payment_status' => PaymentStatus::Awaiting,
            'deposit_amount' => $deposit,
            'payment_due_at' => $this->dueAt($startsAt),
        ], $items];
    }

    /**
     * The QR codes the customer should pay: one per service with its own downpayment QR (amount built in), and the
     * salon's any-amount QR for whatever the salon-wide rule added.
     *
     * @return list<array{label: string, amount: float, qr_url: ?string, fixed_amount: bool}>
     */
    public function qrCodesFor(Appointment $appointment): array
    {
        // Only needed while the customer still has to pay.
        if (! $appointment->payment_status->acceptsProof() || (float) $appointment->deposit_amount <= 0) {
            return [];
        }

        $codes = [];
        $own = 0.0;
        foreach ($appointment->items as $item) {
            if ((float) $item->deposit_amount <= 0) {
                continue;
            }
            $own += (float) $item->deposit_amount;
            if ($item->service?->gcash_qr) {
                $codes[] = [
                    'label' => $item->service_name,
                    'amount' => round((float) $item->deposit_amount, 2),
                    'qr_url' => Fmt::url($item->service->gcash_qr),
                    'fixed_amount' => true,
                ];
            }
        }

        $rest = round((float) $appointment->deposit_amount - $own, 2);
        $salonQr = $this->settings->get('payment_gcash_qr');
        if ($rest > 0 && $salonQr) {
            $codes[] = ['label' => (string) $this->settings->get('salon_name'), 'amount' => $rest, 'qr_url' => Fmt::url($salonQr), 'fixed_amount' => false];
        }

        return $codes;
    }

    /** The customer can upload a receipt now (also accepted a little late, until the expiry job has run). */
    public function canSubmit(Appointment $appointment): bool
    {
        return $appointment->payment_status->acceptsProof()
            && $appointment->status->isActive()
            && ! $appointment->checked_in_at;
    }

    public function submitProof(Appointment $appointment, User $customer, string $reference, UploadedFile $proof): Appointment
    {
        $reference = self::normalizeReference($reference);
        $path = null;

        try {
            $payment = DB::transaction(function () use ($appointment, $customer, $reference, $proof, &$path) {
                $locked = $this->lock($appointment);
                if (! $this->canSubmit($locked)) {
                    throw ApiException::conflict($this->submitBlockedReason($locked));
                }
                $this->assertReferenceUnused($reference, $locked->id);

                $path = $this->uploads->storePrivateImage($proof, 'payments');
                $payment = $locked->payments()->create([
                    'customer_id' => $customer->id,
                    'method' => Payment::METHOD_GCASH,
                    'amount' => $locked->deposit_amount,
                    'reference_number' => $reference,
                    'proof_path' => $path,
                    'status' => Payment::SUBMITTED,
                ]);

                $old = $locked->payment_status->value;
                $locked->update(['payment_status' => PaymentStatus::Submitted]);
                $this->audit->log('appointment.payment_submitted', $locked, ['payment_status' => $old], [
                    'payment_status' => PaymentStatus::Submitted->value,
                    'reference_number' => $reference,
                ]);

                return $payment;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk(FileUploadService::privateDisk())->delete($path);
            }
            throw $e;
        }

        $appointment = $this->fresh($appointment);
        $this->notifications->paymentSubmitted($appointment, $payment);

        return $appointment;
    }

    /**
     * Staff confirm the money arrived. Approves the receipt waiting for review, or, when none is waiting and a
     * $reference is given, records a payment the customer sent outside the app (e.g. receipt sent by chat).
     * A booking that was on hold is confirmed.
     */
    public function approve(Appointment $appointment, User $actor, float $amountReceived, ?string $reference = null): Appointment
    {
        $confirmed = DB::transaction(function () use ($appointment, $actor, $amountReceived, $reference) {
            $locked = $this->lock($appointment);
            $review = ['status' => Payment::APPROVED, 'amount_received' => $amountReceived, 'reviewed_by' => $actor->id, 'reviewed_at' => now()];

            if ($payment = $this->waitingReceipt($locked)) {
                $payment->update($review);
            } elseif ($reference !== null && $locked->payment_status->canRecordManually()) {
                $reference = self::normalizeReference($reference);
                $this->assertReferenceUnused($reference, $locked->id);
                $payment = $locked->payments()->create([
                    'customer_id' => $locked->customer_id,
                    'method' => Payment::METHOD_GCASH,
                    'amount' => (float) $locked->deposit_amount > 0 ? $locked->deposit_amount : $amountReceived,
                    'reference_number' => $reference,
                    ...$review,
                ]);
            } else {
                throw ApiException::conflict('There is no GCash receipt waiting for review.');
            }

            $confirm = in_array($locked->status, self::HOLDING_STATUSES, true);
            $old = ['payment_status' => $locked->payment_status->value, 'status' => $locked->status->value];
            $locked->update([
                'payment_status' => PaymentStatus::Paid,
                'amount_paid' => round((float) $locked->amount_paid + $amountReceived, 2),
                'paid_at' => now(),
                'payment_due_at' => null,
                ...($confirm ? ['status' => AppointmentStatus::Confirmed, 'confirmed_at' => now()] : []),
            ]);
            $this->audit->log('appointment.payment_approved', $locked, $old, [
                'payment_status' => PaymentStatus::Paid->value,
                'status' => $locked->status->value,
                'amount' => $amountReceived,
                'reference_number' => $payment->reference_number,
            ]);

            return $confirm;
        });

        $appointment = $this->fresh($appointment);
        $this->notifications->paymentConfirmed($appointment, $confirmed);

        return $appointment;
    }

    /** The receipt could not be matched. The customer gets a fresh window to send a new one. */
    public function reject(Appointment $appointment, User $actor, string $reason): Appointment
    {
        DB::transaction(function () use ($appointment, $actor, $reason) {
            $locked = $this->lock($appointment);
            $payment = $this->waitingReceipt($locked)
                ?? throw ApiException::conflict('There is no GCash receipt waiting for review.');

            $payment->update([
                'status' => Payment::REJECTED,
                'rejection_reason' => $reason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $holding = in_array($locked->status, self::HOLDING_STATUSES, true);
            $locked->update([
                'payment_status' => PaymentStatus::Rejected,
                'payment_due_at' => $holding ? $this->dueAt($locked->startsAt()) : null,
                'payment_reminded_at' => null,
            ]);
            $this->audit->log('appointment.payment_rejected', $locked, ['payment_status' => PaymentStatus::Submitted->value], [
                'payment_status' => PaymentStatus::Rejected->value,
                'reason' => $reason,
            ]);
        });

        $appointment = $this->fresh($appointment);
        if ($appointment->status->isActive()) {
            $this->notifications->paymentRejected($appointment, $reason);
        }

        return $appointment;
    }

    /** No deposit needed for this booking after all (e.g. a regular customer). Confirms a held booking. */
    public function waive(Appointment $appointment, User $actor): Appointment
    {
        $confirmed = DB::transaction(function () use ($appointment) {
            $locked = $this->lock($appointment);
            if (! $locked->payment_status->acceptsProof() || ! $locked->status->isActive()) {
                throw ApiException::conflict("The deposit cannot be waived while it is {$locked->payment_status->label()}.");
            }

            $confirm = in_array($locked->status, self::HOLDING_STATUSES, true);
            $old = ['payment_status' => $locked->payment_status->value, 'status' => $locked->status->value];
            $locked->update([
                'payment_status' => PaymentStatus::Waived,
                'payment_due_at' => null,
                ...($confirm ? ['status' => AppointmentStatus::Confirmed, 'confirmed_at' => now()] : []),
            ]);
            $this->audit->log('appointment.payment_waived', $locked, $old, [
                'payment_status' => PaymentStatus::Waived->value,
                'status' => $locked->status->value,
            ]);

            return $confirm;
        });

        $appointment = $this->fresh($appointment);
        if ($confirmed) {
            $this->notifications->appointmentConfirmed($appointment);
        }

        return $appointment;
    }

    /** Staff sent the deposit back (through GCash, outside the app) for a cancelled or no-show booking. */
    public function markRefunded(Appointment $appointment, User $actor, ?string $note = null): Appointment
    {
        DB::transaction(function () use ($appointment, $note) {
            $locked = $this->lock($appointment);
            if ($locked->payment_status !== PaymentStatus::Paid
                || ! in_array($locked->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
                throw ApiException::conflict('Only a paid deposit on a cancelled or no-show booking can be marked as refunded.');
            }

            $amount = (float) $locked->amount_paid;
            $locked->update(['payment_status' => PaymentStatus::Refunded, 'amount_paid' => 0]);
            $this->audit->log('appointment.payment_refunded', $locked, ['payment_status' => PaymentStatus::Paid->value], array_filter([
                'payment_status' => PaymentStatus::Refunded->value,
                'amount' => $amount,
                'note' => $note,
            ]));
        });

        $appointment = $this->fresh($appointment);
        $this->notifications->paymentRefunded($appointment);

        return $appointment;
    }

    /** One "your slot is on hold" reminder per payment window. Returns how many were sent. */
    public function remindDueSoon(): int
    {
        $sent = 0;
        $this->holdingQuery()
            ->whereNull('payment_reminded_at')
            ->where('payment_due_at', '>', now())
            ->where('payment_due_at', '<=', now()->addMinutes(self::REMINDER_MINUTES))
            ->chunkById(100, function ($appointments) use (&$sent) {
                foreach ($appointments as $appointment) {
                    // Claim the reminder atomically so overlapping runs never send it twice.
                    $claimed = Appointment::query()->whereKey($appointment->id)->whereNull('payment_reminded_at')
                        ->update(['payment_reminded_at' => now()]);
                    if ($claimed) {
                        $this->notifications->paymentReminder($this->fresh($appointment));
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /** Cancels held bookings whose deadline passed, releasing the slot. Returns how many were released. */
    public function expireOverdue(): int
    {
        $released = 0;
        $this->holdingQuery()
            ->where('payment_due_at', '<=', now())
            ->chunkById(100, function ($appointments) use (&$released) {
                foreach ($appointments as $appointment) {
                    if ($this->expire($appointment)) {
                        $released++;
                    }
                }
            });

        return $released;
    }

    /** Reference numbers are compared without spaces or dashes ("1234 567 891234" = "1234567891234"). */
    public static function normalizeReference(string $reference): string
    {
        return strtoupper((string) preg_replace('/[\s\-]/', '', $reference));
    }

    private function expire(Appointment $appointment): bool
    {
        $expired = DB::transaction(function () use ($appointment) {
            $locked = Appointment::query()->lockForUpdate()->find($appointment->id);
            // Paid, confirmed, cancelled or given a new deadline since the query ran.
            if (! $locked || ! $this->isHolding($locked) || $locked->payment_due_at->isFuture()) {
                return false;
            }

            $old = ['status' => $locked->status->value, 'payment_status' => $locked->payment_status->value];
            $locked->update([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => null,
                'cancellation_reason' => 'The deposit was not received in time.',
                'payment_status' => PaymentStatus::Expired,
                'payment_due_at' => null,
            ]);
            $this->audit->log('appointment.payment_expired', $locked, $old, [
                'status' => AppointmentStatus::Cancelled->value,
                'payment_status' => PaymentStatus::Expired->value,
            ]);

            return true;
        });

        if ($expired) {
            $this->notifications->paymentExpired($this->fresh($appointment));
        }

        return $expired;
    }

    private function isHolding(Appointment $appointment): bool
    {
        return $appointment->payment_status->isHolding()
            && in_array($appointment->status, self::HOLDING_STATUSES, true)
            && $appointment->payment_due_at !== null;
    }

    /** @return Builder<Appointment> */
    private function holdingQuery()
    {
        return Appointment::query()
            ->whereIn('payment_status', [PaymentStatus::Awaiting->value, PaymentStatus::Rejected->value])
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, self::HOLDING_STATUSES))
            ->whereNotNull('payment_due_at');
    }

    private function waitingReceipt(Appointment $appointment): ?Payment
    {
        if ($appointment->payment_status !== PaymentStatus::Submitted) {
            return null;
        }

        return $appointment->payments()->where('status', Payment::SUBMITTED)->latest('id')->first();
    }

    /** A reference number can back only one booking, unless that receipt was rejected. */
    private function assertReferenceUnused(string $reference, string $appointmentId): void
    {
        $taken = Payment::query()
            ->where('reference_number', $reference)
            ->where('status', '!=', Payment::REJECTED)
            ->where('appointment_id', '!=', $appointmentId)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'reference_number' => 'This reference number was already used for another booking.',
            ]);
        }
    }

    private function submitBlockedReason(Appointment $appointment): string
    {
        return match (true) {
            $appointment->payment_status === PaymentStatus::Submitted => 'Your receipt is already with the salon for checking.',
            in_array($appointment->payment_status, [PaymentStatus::Paid, PaymentStatus::Waived], true) => 'This booking is already settled.',
            $appointment->payment_status === PaymentStatus::NotRequired => 'This booking does not need a deposit.',
            $appointment->payment_status === PaymentStatus::Expired => 'The payment window has closed and the slot was released. If you already paid, please contact the salon.',
            default => "A {$appointment->status->label()} booking cannot take a payment.",
        };
    }

    private function lock(Appointment $appointment): Appointment
    {
        return Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
    }

    private function fresh(Appointment $appointment): Appointment
    {
        return $appointment->fresh(['customer', 'branch', 'staff.user', 'promotion', 'items.staff.user', 'review.images', 'latestPayment']);
    }
}
