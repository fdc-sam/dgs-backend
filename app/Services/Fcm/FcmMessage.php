<?php

namespace App\Services\Fcm;

final class FcmMessage
{
    /** @param  array<string, string>  $data  FCM data values must be strings */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly string $channelId = 'general',
    ) {}
}
