<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\DeviceTokenRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use App\Services\Supabase\RealtimeTokenService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->paginated(
            NotificationResource::collection($user->notifications()->latest()->paginate($this->perPage(20))),
            extraMeta: ['unread_count' => $user->unreadNotifications()->count()],
        );
    }

    /** Credentials for subscribing to new notifications over Supabase Realtime (in-app, live). */
    public function realtime(Request $request, RealtimeTokenService $tokens): JsonResponse
    {
        if (! $tokens->isConfigured()) {
            return $this->ok(['enabled' => false]);
        }

        $user = $request->user();
        $issued = $tokens->issue($user);

        return $this->ok([
            'enabled' => true,
            'url' => rtrim((string) config('services.supabase.url'), '/'),
            'publishable_key' => config('services.supabase.publishable_key'),
            'access_token' => $issued['token'],
            'expires_at' => Carbon::createFromTimestamp($issued['expires_at'])->toAtomString(),
            'schema' => 'public',
            'table' => 'notifications',
            'user_id' => $user->id,
        ]);
    }

    public function registerDevice(DeviceTokenRequest $request, NotificationService $notifications): JsonResponse
    {
        $notifications->registerDevice($request->user(), $request->input('token'), $request->input('platform'), $request->input('device_name'));

        return $this->ok(null, 'Device registered for notifications.');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        return $this->ok(new NotificationResource($model));
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(null, 'All notifications marked as read.');
    }
}
