<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? 'GENERAL',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'data' => (object) ($data['data'] ?? []),
            'read_at' => Fmt::iso($this->read_at),
            'created_at' => Fmt::iso($this->created_at),
        ];
    }
}
