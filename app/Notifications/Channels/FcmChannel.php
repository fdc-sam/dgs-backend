<?php

namespace App\Notifications\Channels;

use App\Services\Fcm\FcmClient;
use App\Services\Fcm\FcmMessage;
use Illuminate\Notifications\Notification;

class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $tokens = $notifiable->routeNotificationFor('fcm', $notification) ?? [];
        $message = $notification->toFcm($notifiable);

        // Include the stored notification id so a tap can mark it read.
        $message = new FcmMessage(
            $message->title,
            $message->body,
            $message->data + ['notification_id' => (string) $notification->id],
            $message->channelId,
        );

        $this->client->send($tokens, $message);
    }
}
