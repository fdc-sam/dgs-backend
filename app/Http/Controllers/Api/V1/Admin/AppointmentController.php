<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CancelAppointmentRequest;
use App\Http\Requests\Api\CreateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Appointments in the admin app. Managers see and act on every appointment; staff only on their own
 * (the same rules as the web portal, enforced by AppointmentPolicy).
 */
class AppointmentController extends ApiController
{
    private const RELATIONS = ['customer', 'branch', 'staff.user', 'items.staff.user', 'promotion', 'review.images', 'latestPayment'];

    public function __construct(private readonly AppointmentService $appointments) {}

    /** The day's appointments (default today) with a count per status for the dashboard. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $date = $filters['date'] ?? now()->toDateString();

        $day = Appointment::query()
            ->when(! $user->isManager(), fn ($q) => $q->where(fn ($q) => $q->where('staff_profile_id', $user->staffProfile?->id ?? '')
                ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $user->staffProfile?->id ?? ''))))
            ->whereDate('appointment_date', $date)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));

        // toBase(): plain rows, so `status` stays a string instead of being cast to the AppointmentStatus enum.
        $summary = (clone $day)->toBase()->selectRaw('status, COUNT(*) as n, SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as arrived')->groupBy('status')->get();
        $count = fn (AppointmentStatus ...$statuses) => (int) $summary->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->sum('n');

        $list = (clone $day)->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->whereLike('appointment_number', $like)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)));
            })
            ->orderBy('start_time')->orderBy('id')
            ->paginate($this->perPage(50));

        return $this->ok(AppointmentResource::collection($list), null, 200, [
            'date' => $date,
            'summary' => [
                'total' => (int) $summary->sum('n'),
                'pending' => $count(AppointmentStatus::Pending, AppointmentStatus::Rescheduled),
                'confirmed' => $count(AppointmentStatus::Confirmed),
                'in_progress' => $count(AppointmentStatus::InProgress),
                'completed' => $count(AppointmentStatus::Completed),
                'cancelled' => $count(AppointmentStatus::Cancelled),
                'no_show' => $count(AppointmentStatus::NoShow),
                'arrived' => (int) $summary->sum('arrived'),
            ],
        ]);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)));
    }

    /** The customer is at the branch: confirm the booking (if it was pending) and record their arrival. */
    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('checkIn', $appointment);
        $alreadyArrived = $appointment->checked_in_at !== null;
        $appointment = $this->appointments->checkIn($appointment, $request->user());

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)), $alreadyArrived ? 'Already checked in.' : 'Checked in. The customer is confirmed.');
    }

    /** Confirm / start / complete / no-show. */
    public function status(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('updateStatus', $appointment);
        $data = $request->validate([
            'status' => ['required', Rule::in([AppointmentStatus::Confirmed->value, AppointmentStatus::InProgress->value, AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value])],
        ]);
        $to = AppointmentStatus::from($data['status']);
        $appointment = $this->appointments->transition($appointment, $to, $request->user());

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)), 'Appointment marked as '.$to->label().'.');
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);
        $appointment = $this->appointments->cancel($appointment, $request->user(), $request->input('reason'));

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)), 'Appointment cancelled. The customer has been notified.');
    }

    public function notes(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('updateNotes', $appointment);
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:2000']]);
        $appointment->update($data);

        return $this->ok(new AppointmentResource($appointment->load(self::RELATIONS)), 'Notes saved.');
    }

    /** Book at the counter for a customer who is here: confirmed and checked in straight away (managers only). */
    public function store(CreateAppointmentRequest $request, User $customer): JsonResponse
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('createForCustomer', Appointment::class);
        if (! $customer->isActive()) {
            abort(403, "This customer's account is {$customer->status->value}.");
        }

        $appointment = $this->appointments->bookWalkIn($customer, $request->user(), $request->validated());

        return $this->created(new AppointmentResource($appointment->load(self::RELATIONS)), 'Booked and checked in.');
    }
}
