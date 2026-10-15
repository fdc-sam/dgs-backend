<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
            'promotions' => Promotion::currentlyActive()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        $appointmentId = $model->data['data']['appointment_id'] ?? null;

        return $appointmentId ? redirect()->route('appointments.show', $appointmentId) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function broadcast(Request $request, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([NotificationType::Promotion->value, NotificationType::General->value])],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:240'],
            'promotion_id' => ['nullable', 'required_if:type,PROMOTION', 'string', 'exists:promotions,id'],
        ]);

        $count = $notifications->broadcastToCustomers(
            NotificationType::from($data['type']),
            $data['title'],
            $data['body'],
            array_filter(['promotion_id' => $data['promotion_id'] ?? null]),
        );
        $audit->log('notification.broadcast', 'notification', null, [...$data, 'recipients' => $count]);

        return back()->with('success', "Notification queued for {$count} customer(s).");
    }
}
