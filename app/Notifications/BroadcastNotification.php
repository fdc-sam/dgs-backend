<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\FcmChannel;
use App\Services\Fcm\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** A message to customers that is not about an appointment: an announcement, a promotion or a referral update. */
class BroadcastNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, string>  $data  deep-link keys such as promotion_id */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

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
        return ['type' => $this->type->value, 'title' => $this->title, 'body' => $this->body, 'data' => $this->data];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return new FcmMessage($this->title, $this->body, ['type' => $this->type->value, ...$this->data],
            $this->type === NotificationType::Promotion ? 'promotions' : 'general');
    }
}
