<?php

namespace App\Enums;

/**
 * Deposit state of an appointment (manual GCash: customer pays, uploads the receipt, staff verify it).
 *
 * not_required → no deposit asked (payments off, walk-in, or booked by staff)
 * awaiting_payment → slot held until payment_due_at; customer may upload a receipt
 * for_verification → receipt uploaded, waiting for staff to check it against GCash
 * paid / waived → resolved; the booking is confirmed
 * rejected → receipt refused (reason given); customer may upload again before the new deadline
 * expired → deadline passed with no receipt; the booking was cancelled and the slot released
 * void → booking cancelled before anything was paid
 * refunded → the salon returned the deposit
 */
enum PaymentStatus: string
{
    case NotRequired = 'not_required';
    case Awaiting = 'awaiting_payment';
    case Submitted = 'for_verification';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Waived = 'waived';
    case Expired = 'expired';
    case Void = 'void';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Pay at salon',
            self::Awaiting => 'Awaiting payment',
            self::Submitted => 'For verification',
            self::Paid => 'Deposit paid',
            self::Rejected => 'Receipt rejected',
            self::Waived => 'Deposit waived',
            self::Expired => 'Expired',
            self::Void => 'Void',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Awaiting, self::Rejected => 'warning',
            self::Submitted => 'info',
            self::Paid, self::Waived => 'success',
            self::Expired => 'danger',
            self::NotRequired, self::Void, self::Refunded => 'neutral',
        };
    }

    /** The customer can (re)upload a receipt. */
    public function acceptsProof(): bool
    {
        return in_array($this, [self::Awaiting, self::Rejected], true);
    }

    /** Staff can record a payment received outside the app (e.g. receipt sent by chat). */
    public function canRecordManually(): bool
    {
        return in_array($this, [self::Awaiting, self::Rejected, self::Expired, self::Void], true);
    }

    /** A deadline is running: the booking is cancelled automatically when payment_due_at passes. */
    public function isHolding(): bool
    {
        return $this->acceptsProof();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
