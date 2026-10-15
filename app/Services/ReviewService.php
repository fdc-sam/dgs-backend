<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(
        private readonly FileUploadService $uploads,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{appointment_id: string, rating: int, review?: ?string, service_id?: ?string}  $data
     * @param  list<UploadedFile>  $images
     */
    public function create(User $customer, array $data, array $images = []): Review
    {
        $appointment = Appointment::with('items')->findOrFail($data['appointment_id']);

        if ($appointment->customer_id !== $customer->id) {
            throw ApiException::forbidden('You can only review your own appointments.');
        }
        if ($appointment->status !== AppointmentStatus::Completed) {
            throw ApiException::conflict('Only completed appointments can be reviewed.');
        }
        if ($appointment->review()->withTrashed()->exists()) {
            throw ApiException::conflict('You have already reviewed this appointment.');
        }

        $serviceId = $data['service_id'] ?? $appointment->items->first()?->service_id;
        if ($serviceId && ! $appointment->items->contains('service_id', $serviceId)) {
            throw ValidationException::withMessages(['service_id' => 'This service was not part of the appointment.']);
        }

        $paths = array_map(fn (UploadedFile $file) => $this->uploads->storeImage($file, 'reviews'), $images);

        try {
            return DB::transaction(function () use ($customer, $appointment, $serviceId, $data, $paths) {
                $review = Review::create([
                    'appointment_id' => $appointment->id,
                    'customer_id' => $customer->id,
                    'service_id' => $serviceId,
                    'rating' => $data['rating'],
                    'review' => $data['review'] ?? null,
                    'status' => $this->settings->bool('auto_approve_reviews') ? ReviewStatus::Approved : ReviewStatus::Pending,
                ]);
                foreach ($paths as $path) {
                    $review->images()->create(['path' => $path]);
                }

                return $review->load(['images', 'customer', 'service', 'appointment.items']);
            });
        } catch (UniqueConstraintViolationException) {
            // Unique index on reviews.appointment_id caught a concurrent duplicate submit.
            array_map(fn ($p) => $this->uploads->delete($p), $paths);
            throw ApiException::conflict('You have already reviewed this appointment.');
        }
    }

    public function moderate(Review $review, ReviewStatus $status): Review
    {
        $old = ['status' => $review->status->value];
        $review->update(['status' => $status]);
        $this->audit->log('review.'.$status->value, $review, $old, ['status' => $status->value]);

        return $review;
    }

    public function delete(Review $review): void
    {
        $this->audit->log('review.deleted', $review, ['rating' => $review->rating, 'review' => $review->review]);
        $review->delete();
    }
}
