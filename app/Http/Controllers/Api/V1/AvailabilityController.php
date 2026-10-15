<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends ApiController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AppointmentService $appointments,
    ) {}

    public function day(AvailabilityRequest $request): JsonResponse
    {
        [$branch, $selections, $staff, $exclude] = $this->resolve($request);

        return $this->ok(new AvailabilityResource(
            $this->availability->forDay($branch, $selections, $staff, CarbonImmutable::parse($request->input('date')), $exclude)
        ));
    }

    public function dates(AvailabilityRequest $request): JsonResponse
    {
        [$branch, $selections, $staff, $exclude] = $this->resolve($request);
        $month = CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01')->startOfDay();

        return $this->ok([
            'month' => $month->format('Y-m'),
            'dates' => $this->availability->forMonth($branch, $selections, $staff, $month, $exclude),
        ]);
    }

    private function resolve(AvailabilityRequest $request): array
    {
        $branch = Branch::query()->active()->findOrFail($request->input('branch_id'));
        $selections = $this->appointments->resolveSelections($branch, [[
            'service_id' => $request->input('service_id'),
            'service_variant_id' => $request->input('service_variant_id'),
        ]]);

        $staff = null;
        if ($request->filled('staff_id')) {
            $staff = StaffProfile::query()->bookable()->find($request->input('staff_id'))
                ?? throw ValidationException::withMessages(['staff_id' => 'The selected staff member is not available.']);
        }

        // Only the owner may exclude their own appointment (rescheduling).
        $exclude = null;
        if ($request->filled('exclude_appointment_id') && ($user = auth('sanctum')->user())) {
            $exclude = Appointment::whereKey($request->input('exclude_appointment_id'))
                ->where('customer_id', $user->id)->value('id');
        }

        return [$branch, $selections, $staff, $exclude];
    }
}
