<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password reset email carrying a 6-digit reset code for the mobile app (and a deep link). */
class ResetPasswordCode extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deepLink = 'dgssalon://reset-password?'.http_build_query(['token' => $this->code, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset the password for your account.')
            ->line('Open the app on your phone and enter this reset code:')
            ->line('**'.$this->code.'**')
            ->action('Open in the app', $deepLink)
            ->line("This code expires in {$minutes} minutes. If you did not request a reset, you can ignore this email.");
    }
}
