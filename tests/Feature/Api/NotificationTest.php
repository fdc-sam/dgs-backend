<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationType;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentService;
use App\Services\Fcm\FcmClient;
use App\Services\Fcm\FcmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    public function test_list_read_and_read_all(): void
    {
        $user = User::factory()->create();
        $appointment = Appointment::factory()->create(['customer_id' => $user->id]);
        $user->notify(new AppointmentNotification($appointment, NotificationType::AppointmentConfirmed, 'Confirmed', 'Body 1'));
        $user->notify(new AppointmentNotification($appointment, NotificationType::AppointmentReminder, 'Reminder', 'Body 2'));
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.data.appointment_id', $appointment->id);
        $this->assertContains($response->json('data.0.type'), ['APPOINTMENT_CONFIRMED', 'APPOINTMENT_REMINDER']);

        $id = $response->json('data.0.id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 1);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_cannot_read_others_notification(): void
    {
        $other = User::factory()->create();
        $other->notify(new AppointmentNotification(Appointment::factory()->create(), NotificationType::General, 'x', 'y'));
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/notifications/'.$other->notifications()->first()->id.'/read')->assertNotFound();
    }

    public function test_device_token_registration_moves_between_users(): void
    {
        $token = str_repeat('f', 64);
        $first = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android', 'device_name' => 'Pixel'])->assertOk();
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android'])->assertOk();
        $this->assertSame(1, $first->deviceTokens()->count());

        $second = User::factory()->create();
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android'])->assertOk();
        $this->assertSame(0, $first->deviceTokens()->count());
        $this->assertSame(1, $second->deviceTokens()->count());

        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'windows'])->assertStatus(422);
    }

    public function test_admin_confirmation_notifies_customer(): void
    {
        $this->setUpSalon();
        Notification::fake();
        $customer = $this->customer();
        $appointment = Appointment::factory()->at(self::DAY.' 09:00')->create(['customer_id' => $customer->id, 'branch_id' => $this->branch->id]);

        app(AppointmentService::class)->transition($appointment, AppointmentStatus::Confirmed, User::factory()->admin()->create());

        Notification::assertSentTo($customer, AppointmentNotification::class, fn ($n) => $n->type === NotificationType::AppointmentConfirmed);
    }

    public function test_fcm_client_sends_http_v1_and_prunes_dead_tokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        config(['services.fcm' => ['project_id' => 'demo-project', 'client_email' => 'sa@demo.iam.gserviceaccount.com', 'private_key' => $pem]]);

        $user = User::factory()->create();
        $user->deviceTokens()->create(['token' => 'good-token-aaaaaaaaaaaaaaaa', 'platform' => 'android']);
        $user->deviceTokens()->create(['token' => 'dead-token-aaaaaaaaaaaaaaaa', 'platform' => 'android']);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => function ($request) {
                return $request['message']['token'] === 'dead-token-aaaaaaaaaaaaaaaa'
                    ? Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404)
                    : Http::response(['name' => 'projects/demo-project/messages/1']);
            },
        ]);

        app(FcmClient::class)->send($user->routeNotificationForFcm(), new FcmMessage('Hi', 'There', ['appointment_id' => 'abc']));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/projects/demo-project/messages:send')
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['data']['appointment_id'] === 'abc');
        $this->assertSame(['good-token-aaaaaaaaaaaaaaaa'], $user->deviceTokens()->pluck('token')->all());
    }
}
