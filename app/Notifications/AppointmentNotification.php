<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Notifications\Channels\FcmChannel;
use App\Services\Fcm\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Appointment lifecycle notification stored in the database (in-app list / admin bell) and pushed via FCM.
 * One class keyed by NotificationType keeps copy and payload consistent across events.
 */
class AppointmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        // With Supabase, the database row itself triggers the push (send-push Edge Function).
        return config('services.push.driver') === 'supabase' ? ['database'] : ['database', FcmChannel::class];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type->value;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'data' => [
                'appointment_id' => $this->appointment->id,
                'appointment_number' => $this->appointment->appointment_number,
            ],
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return new FcmMessage($this->title, $this->body, [
            'type' => $this->type->value,
            'appointment_id' => $this->appointment->id,
        ], 'appointments');
    }
}
