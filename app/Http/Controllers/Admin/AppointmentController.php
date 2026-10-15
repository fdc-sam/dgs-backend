<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentFilterRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use App\Support\Booking\ServiceSelection;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(AppointmentFilterRequest $request): View
    {
        $filters = $request->validated();
        $user = $request->user();

        $appointments = Appointment::query()
            ->with(['customer', 'branch', 'staff.user', 'items.staff.user'])
            ->when($user->isStaff(), fn ($q) => $q->where(fn ($q) => $q->where('staff_profile_id', $user->staffProfile?->id ?? '')
                ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $user->staffProfile?->id ?? ''))))
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->whereDate('appointment_date', $d))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('appointment_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('appointment_date', '<=', $d))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('staff_profile_id', $id)
                ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $id))))
            ->when($filters['service_id'] ?? null, fn ($q, $id) => $q->whereHas('items', fn ($i) => $i->where('service_id', $id)))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['customer'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->whereLike('appointment_number', $like)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('phone', $like)));
            })
            ->orderByDesc('appointment_date')->orderByDesc('start_time')
            ->paginate(20)->withQueryString();

        return view('admin.appointments.index', [
            'appointments' => $appointments,
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'staff' => StaffProfile::with('user')->get()->sortBy('user.name')->mapWithKeys(fn ($s) => [$s->id => $s->user?->name]),
            'services' => Service::orderBy('name')->pluck('name', 'id'),
            'statuses' => collect(AppointmentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
        ]);
    }

    public function show(Appointment $appointment): View
    {
        $this->authorize('view', $appointment);

        $appointment->load(['customer.customerProfile', 'branch', 'staff.user', 'items.staff.user', 'promotion', 'review.images', 'cancelledBy', 'checkedInBy', 'payments.reviewer']);
        $history = AuditLog::with('user')
            ->where('entity_type', 'appointment')->where('entity_id', $appointment->id)
            ->latest('created_at')->get();

        return view('admin.appointments.show', [
            'appointment' => $appointment,
            'history' => $history,
            'staffOptions' => StaffProfile::query()->bookable()->atBranch($appointment->branch_id)->with('user')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->user?->name]),
            'transitions' => collect($appointment->status->allowedTransitions())
                ->reject(fn ($s) => in_array($s, [AppointmentStatus::Cancelled, AppointmentStatus::Rescheduled], true)),
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('updateStatus', $appointment);
        $data = $request->validate([
            'status' => ['required', Rule::in([AppointmentStatus::Confirmed->value, AppointmentStatus::InProgress->value, AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value])],
        ]);

        return $this->attempt(fn () => $this->appointments->transition($appointment, AppointmentStatus::from($data['status']), $request->user()),
            'Appointment marked as '.AppointmentStatus::from($data['status'])->label().'.');
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('cancel', $appointment);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->appointments->cancel($appointment, $request->user(), $data['reason'] ?? null), 'Appointment cancelled. The customer has been notified.');
    }

    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('reschedule', $appointment);
        $data = $request->validate([
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'staff_id' => ['nullable', 'string', 'exists:staff_profiles,id'],
        ]);

        return $this->attempt(fn () => $this->appointments->reschedule($appointment, $request->user(), $data['appointment_date'], $data['start_time'], $data['staff_id'] ?? null),
            'Appointment rescheduled. The customer has been notified.');
    }

    public function updateNotes(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('updateNotes', $appointment);
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:2000']]);
        $appointment->update($data);

        return back()->with('success', 'Internal notes saved.');
    }

    /** Free start times for the reschedule dialog (excludes this appointment itself). */
    public function slots(Request $request, Appointment $appointment, AvailabilityService $availability): JsonResponse
    {
        $this->authorize('reschedule', $appointment);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'string', 'exists:staff_profiles,id'],
        ]);

        $appointment->loadMissing(['items.service.branches', 'items.variant', 'branch']);
        $selections = $appointment->items->filter(fn ($i) => $i->service)->map(fn ($i) => new ServiceSelection(
            $i->service, $i->variant, (float) $i->unit_price, $i->duration_minutes, $i->service->buffer_minutes,
        ))->values();
        $staff = ! empty($data['staff_id']) ? StaffProfile::find($data['staff_id']) : $appointment->staff;

        $day = $availability->forDay($appointment->branch, $selections, $staff, CarbonImmutable::parse($data['date']), $appointment->id);

        return response()->json([
            'closed_reason' => $day->isOpen ? null : $day->closedReason,
            'slots' => array_values(array_map(fn ($s) => $s->start->format('H:i'), array_filter($day->slots, fn ($s) => $s->available))),
        ]);
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (ApiException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
