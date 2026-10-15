<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\Service;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private function completedAppointment($customer, AppointmentStatus $status = AppointmentStatus::Completed): Appointment
    {
        $service = Service::factory()->create();
        $appointment = Appointment::factory()->at('2026-09-20 10:00')->status($status)->create(['customer_id' => $customer->id]);
        $appointment->items()->create(['service_id' => $service->id, 'service_name' => $service->name, 'unit_price' => 100, 'duration_minutes' => 60, 'subtotal' => 100]);

        return $appointment;
    }

    public function test_customer_reviews_completed_appointment_with_images(): void
    {
        Storage::fake('public');
        $customer = $this->actingAsCustomer();
        $appointment = $this->completedAppointment($customer);

        $response = $this->post('/api/v1/reviews', [
            'appointment_id' => $appointment->id,
            'rating' => 5,
            'review' => 'Loved it!',
            'images' => [UploadedFile::fake()->image('a.jpg', 2400, 1800), UploadedFile::fake()->image('b.png')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.service_id', $appointment->items->first()->service_id)
            ->assertJsonCount(2, 'data.images');

        $path = Review::first()->images->first()->path;
        $this->assertStringStartsWith('salon/reviews/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
        [$width] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(1600, $width); // downscaled

        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.review.rating', 5);
    }

    public function test_only_once_per_appointment(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->completedAppointment($customer);

        $this->postJson('/api/v1/reviews', ['appointment_id' => $appointment->id, 'rating' => 4])->assertCreated();
        $this->postJson('/api/v1/reviews', ['appointment_id' => $appointment->id, 'rating' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_only_completed_appointments(): void
    {
        $customer = $this->actingAsCustomer();

        foreach ([AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Cancelled] as $status) {
            $this->postJson('/api/v1/reviews', ['appointment_id' => $this->completedAppointment($customer, $status)->id, 'rating' => 4])
                ->assertStatus(409);
        }
    }

    public function test_cannot_review_others_appointment(): void
    {
        $appointment = $this->completedAppointment($this->customer());
        $this->actingAsCustomer();

        $this->postJson('/api/v1/reviews', ['appointment_id' => $appointment->id, 'rating' => 5])->assertForbidden();
    }

    public function test_rating_validation_and_file_type(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->completedAppointment($customer);

        $this->postJson('/api/v1/reviews', ['appointment_id' => $appointment->id, 'rating' => 6])->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->post('/api/v1/reviews', [
            'appointment_id' => $appointment->id, 'rating' => 5,
            'images' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('images.0');
    }

    public function test_public_list_shows_only_approved_with_masked_names(): void
    {
        $customer = $this->customer(['name' => 'Juan Dela Cruz']);
        $approved = Review::factory()->approved()->create(['appointment_id' => $this->completedAppointment($customer)->id]);
        $pending = Review::factory()->create();

        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name', 'Juan C.');
        $this->getJson("/api/v1/reviews/{$pending->id}")->assertNotFound();
        $this->getJson("/api/v1/reviews/{$approved->id}")->assertOk();

        $this->actingAsCustomer($pending->customer);
        $this->getJson('/api/v1/reviews?mine=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
        $this->getJson("/api/v1/reviews/{$pending->id}")->assertOk();
    }

    public function test_auto_approve_setting(): void
    {
        app(SettingsService::class)->update(['auto_approve_reviews' => true]);
        $customer = $this->actingAsCustomer();

        $this->postJson('/api/v1/reviews', ['appointment_id' => $this->completedAppointment($customer)->id, 'rating' => 5])
            ->assertCreated()->assertJsonPath('data.status', 'approved');
    }
}
