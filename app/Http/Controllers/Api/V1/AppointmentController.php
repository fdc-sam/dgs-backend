<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CancelAppointmentRequest;
use App\Http\Requests\Api\CreateAppointmentRequest;
use App\Http\Requests\Api\RescheduleAppointmentRequest;
use App\Http\Requests\Api\SubmitPaymentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends ApiController
{
    private const RELATIONS = ['branch', 'staff.user', 'promotion', 'items.staff.user', 'review.images', 'review.customer', 'latestPayment'];

    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['nullable', Rule::in(['upcoming', 'past', 'cancelled'])]]);

        $query = $request->user()->appointments()->with(self::RELATIONS);

        match ($request->input('scope')) {
            'upcoming' => $query->upcoming()->orderBy('starts_at'),
            'past' => $query->where(fn ($q) => $q
                ->whereIn('status', [AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value])
                ->orWhere(fn ($q) => $q->where('starts_at', '<', now())->whereNotIn('status', [AppointmentStatus::Cancelled->value])))
                ->orderByDesc('starts_at'),
            'cancelled' => $query->status(AppointmentStatus::Cancelled)->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };

        return $this->ok(AppointmentResource::collection($query->paginate($this->perPage())));
    }

    public function store(CreateAppointmentRequest $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $appointment = $this->appointments->book($request->user(), $request->validated());

        return $this->created(new AppointmentResource($appointment), $appointment->payment_status->acceptsProof()
            ? 'Booking received! Pay the deposit to keep your slot.'
            : 'Booking received! We will confirm it shortly.');
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)));
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);

        $appointment = $this->appointments->cancel($appointment, $request->user(), $request->input('reason'));

        return $this->ok(new AppointmentResource($appointment), 'Your appointment has been cancelled.');
    }

    /** Upload the GCash receipt for the deposit; staff verify it and the booking is confirmed on approval. */
    public function pay(SubmitPaymentRequest $request, Appointment $appointment, PaymentService $payments): JsonResponse
    {
        $this->authorize('pay', $appointment);

        $appointment = $payments->submitProof($appointment, $request->user(), $request->input('reference_number'), $request->file('proof'));

        return $this->ok(new AppointmentResource($appointment), "Receipt sent! We'll confirm your booking once we've checked the payment.");
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('reschedule', $appointment);

        $appointment = $this->appointments->reschedule(
            $appointment,
            $request->user(),
            $request->input('appointment_date'),
            $request->input('start_time'),
            $request->input('staff_id'),
        );

        return $this->ok(new AppointmentResource($appointment), 'Your appointment has been rescheduled.');
    }
}
