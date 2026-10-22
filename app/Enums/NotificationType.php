<?php

namespace App\Enums;

enum NotificationType: string
{
    case AppointmentCreated = 'APPOINTMENT_CREATED';
    case AppointmentConfirmed = 'APPOINTMENT_CONFIRMED';
    case AppointmentReminder = 'APPOINTMENT_REMINDER';
    case AppointmentCancelled = 'APPOINTMENT_CANCELLED';
    case AppointmentRescheduled = 'APPOINTMENT_RESCHEDULED';
    case AppointmentCompleted = 'APPOINTMENT_COMPLETED';
    case PaymentSubmitted = 'PAYMENT_SUBMITTED';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case PaymentRejected = 'PAYMENT_REJECTED';
    case PaymentReminder = 'PAYMENT_REMINDER';
    case PaymentExpired = 'PAYMENT_EXPIRED';
    case PaymentRefunded = 'PAYMENT_REFUNDED';
    case Promotion = 'PROMOTION';
    case Referral = 'REFERRAL';
    case General = 'GENERAL';

    public function label(): string
    {
        return match ($this) {
            self::AppointmentCreated => 'Appointment Created',
            self::AppointmentConfirmed => 'Appointment Confirmed',
            self::AppointmentReminder => 'Appointment Reminder',
            self::AppointmentCancelled => 'Appointment Cancelled',
            self::AppointmentRescheduled => 'Appointment Rescheduled',
            self::AppointmentCompleted => 'Appointment Completed',
            self::PaymentSubmitted => 'Payment Submitted',
            self::PaymentConfirmed => 'Payment Confirmed',
            self::PaymentRejected => 'Payment Rejected',
            self::PaymentReminder => 'Payment Reminder',
            self::PaymentExpired => 'Payment Expired',
            self::PaymentRefunded => 'Payment Refunded',
            self::Promotion => 'Promotion',
            self::Referral => 'Referral',
            self::General => 'General',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
