<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Enums\ReferralStatus;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\DeviceToken;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Notifications\BroadcastNotification;
use App\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/** Decides who hears about each appointment event and with what copy. */
class NotificationService
{
    public function appointmentCreated(Appointment $appointment): void
    {
        $when = $this->when($appointment);
        $next = $appointment->payment_status === PaymentStatus::Awaiting && $appointment->payment_due_at
            ? "Pay the {$this->peso($appointment->deposit_amount)} GCash deposit by {$this->time($appointment->payment_due_at)} to keep your slot."
            : "We'll let you know once it's confirmed.";
        $this->toCustomer($appointment, NotificationType::AppointmentCreated, 'Booking received',
            "We received your booking {$appointment->appointment_number} for {$when}. {$next}");

        $customer = $appointment->customer->name;
        $this->toTeam($appointment, NotificationType::AppointmentCreated, 'New booking',
            "{$customer} booked {$this->services($appointment)} on {$when} at {$appointment->branch->name}.");
    }

    public function appointmentConfirmed(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::AppointmentConfirmed, 'Appointment confirmed',
            "Your appointment {$appointment->appointment_number} on {$this->when($appointment)} is confirmed. See you soon!");
    }

    public function appointmentChargeUpdated(Appointment $appointment): void
    {
        $charge = (float) $appointment->additional_charge > 0
            ? "Additional charge: {$this->peso($appointment->additional_charge)} for {$appointment->additional_charge_reason}. "
            : 'The additional charge was removed. ';
        $this->toCustomer($appointment, NotificationType::General, 'Booking price updated',
            "{$charge}Your new total for appointment {$appointment->appointment_number} is {$this->peso($appointment->total_amount)}.");
    }

    public function appointmentCancelled(Appointment $appointment, User $by): void
    {
        $reason = $appointment->cancellation_reason ? " Reason: {$appointment->cancellation_reason}" : '';

        if ($by->id === $appointment->customer_id) {
            $this->toTeam($appointment, NotificationType::AppointmentCancelled, 'Booking cancelled by customer',
                "{$appointment->customer->name} cancelled {$appointment->appointment_number} ({$this->when($appointment)}).{$reason}");

            return;
        }

        $this->toCustomer($appointment, NotificationType::AppointmentCancelled, 'Appointment cancelled',
            "Your appointment {$appointment->appointment_number} on {$this->when($appointment)} was cancelled by the salon.{$reason}");
    }

    public function appointmentRescheduled(Appointment $appointment, User $by): void
    {
        if ($by->id === $appointment->customer_id) {
            $this->toTeam($appointment, NotificationType::AppointmentRescheduled, 'Booking rescheduled',
                "{$appointment->customer->name} moved {$appointment->appointment_number} to {$this->when($appointment)}.");

            return;
        }

        $this->toCustomer($appointment, NotificationType::AppointmentRescheduled, 'Appointment rescheduled',
            "Your appointment {$appointment->appointment_number} has been moved to {$this->when($appointment)}.");
    }

    public function appointmentCompleted(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::AppointmentCompleted, 'Thanks for visiting!',
            'We hope you loved your new look. Tap to leave a review.');
    }

    public function appointmentReminder(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::AppointmentReminder, 'Upcoming appointment',
            "Reminder: {$this->services($appointment)} on {$this->when($appointment)} at {$appointment->branch->name}.");
    }

    public function paymentSubmitted(Appointment $appointment, Payment $payment): void
    {
        $this->toTeam($appointment, NotificationType::PaymentSubmitted, 'GCash receipt to verify',
            "{$appointment->customer->name} sent a {$this->peso($payment->amount)} deposit for {$appointment->appointment_number} (ref {$payment->reference_number}). Check it in GCash, then approve or reject.",
            includeStaff: false);
    }

    public function paymentConfirmed(Appointment $appointment, bool $confirmedBooking): void
    {
        $tail = $confirmedBooking ? ' Your appointment is confirmed. See you soon!' : '';
        $this->toCustomer($appointment, NotificationType::PaymentConfirmed, 'Payment received',
            "We received your {$this->peso($appointment->amount_paid)} deposit for {$appointment->appointment_number} on {$this->when($appointment)}.{$tail}");
    }

    public function paymentRejected(Appointment $appointment, string $reason): void
    {
        $deadline = $appointment->payment_due_at ? " Please send a new receipt by {$this->time($appointment->payment_due_at)}." : ' Please send a new receipt.';
        $this->toCustomer($appointment, NotificationType::PaymentRejected, 'We could not verify your payment',
            "Your GCash receipt for {$appointment->appointment_number} was not accepted: {$reason}.{$deadline}");
    }

    public function paymentReminder(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::PaymentReminder, 'Your slot is on hold',
            "Pay the {$this->peso($appointment->deposit_amount)} GCash deposit and upload the receipt by {$this->time($appointment->payment_due_at)}, or the {$this->when($appointment)} slot will be released.");
    }

    public function paymentExpired(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::PaymentExpired, 'Booking released',
            "We didn't receive your deposit for {$appointment->appointment_number} in time, so the {$this->when($appointment)} slot was released. If you already paid, contact the salon with your GCash reference number.");
    }

    public function paymentRefunded(Appointment $appointment): void
    {
        $this->toCustomer($appointment, NotificationType::PaymentRefunded, 'Deposit refunded',
            "We refunded your deposit for {$appointment->appointment_number} to your GCash account.");
    }

    /** Tells the referrer where their referral stands: accepted, in progress, completed with the GCash reward, or not eligible. */
    public function referralUpdated(Referral $referral): void
    {
        $name = $referral->name;
        $note = $referral->admin_note ? " {$referral->admin_note}" : '';
        [$title, $body] = match ($referral->status) {
            ReferralStatus::Accepted => ['Referral accepted',
                "We accepted your referral of {$name}. We will reach out to them soon."],
            ReferralStatus::InProgress => ['Referral in progress',
                "Your referral of {$name} is now in progress for {$referral->services->pluck('name')->join(', ', ' and ')}. We will let you know once it is complete."],
            ReferralStatus::Completed => ['Referral successful!',
                "Your referral of {$name} was successful. We sent {$this->peso($referral->reward_amount)} to your GCash number"
                .($referral->referrer?->phone ? " {$referral->referrer->phone}" : '').'. Please check your GCash.'
                .($referral->reward_reference ? " Reference no. {$referral->reward_reference}." : '').$note],
            ReferralStatus::Declined => ['Referral not eligible',
                "Your referral of {$name} is not eligible for a reward.{$note}"],
            default => [null, null],
        };
        if ($title === null) {
            return;
        }

        $referral->referrer?->notify(new BroadcastNotification(NotificationType::Referral, $title, $body, ['referral_id' => $referral->id]));
    }

    /** Send an announcement/promotion to every active customer. Returns the recipient count. */
    public function broadcastToCustomers(NotificationType $type, string $title, string $body, array $data = []): int
    {
        $count = 0;
        User::customers()->where('status', UserStatus::Active->value)->chunkById(200, function ($users) use ($type, $title, $body, $data, &$count) {
            Notification::send($users, new BroadcastNotification($type, $title, $body, $data));
            $count += $users->count();
        });

        return $count;
    }

    public function registerDevice(User $user, string $token, string $platform, ?string $deviceName): DeviceToken
    {
        // A token belongs to one device/user at a time; re-login on a shared phone moves it.
        return DeviceToken::updateOrCreate(['token' => $token], [
            'user_id' => $user->id,
            'platform' => $platform,
            'device_name' => $deviceName,
            'last_used_at' => now(),
        ]);
    }

    private function toCustomer(Appointment $appointment, NotificationType $type, string $title, string $body): void
    {
        $appointment->customer?->notify(new AppointmentNotification($appointment, $type, $title, $body));
    }

    /** Managers plus (unless $includeStaff is false) the assigned staff member — only those on the booking's test/live side. */
    private function toTeam(Appointment $appointment, NotificationType $type, string $title, string $body, bool $includeStaff = true): void
    {
        $recipients = User::managers()->where('is_test', $appointment->is_test)->get();
        $staffUser = $includeStaff ? $appointment->staff?->user : null;
        if ($staffUser && $staffUser->is_test === $appointment->is_test) {
            $recipients->push($staffUser);
        }

        Notification::send($recipients->unique('id'), new AppointmentNotification($appointment, $type, $title, $body));
    }

    private function when(Appointment $appointment): string
    {
        return $appointment->startsAt()->format('D, M j \a\t g:i A');
    }

    private function time(\DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->format('g:i A');
    }

    private function peso(mixed $amount): string
    {
        return Fmt::peso($amount);
    }

    private function services(Appointment $appointment): string
    {
        return $appointment->items->pluck('service_name')->join(', ', ' and ');
    }
}
