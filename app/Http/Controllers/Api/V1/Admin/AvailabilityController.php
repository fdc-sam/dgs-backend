<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Free start times for booking a customer who is at the counter (no minimum-notice or booking-window rules). */
class AvailabilityController extends ApiController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AppointmentService $appointments,
    ) {}

    public function day(AvailabilityRequest $request): JsonResponse
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

        $date = CarbonImmutable::parse($request->input('date'));
        $schedule = $this->availability->forDay($branch, $selections, $staff, $date, null, enforceCustomerRules: false);
        $body = (new AvailabilityResource($schedule))->resolve($request);

        // "Start now": the earliest free time at or after this moment, rounded up to the next 5 minutes.
        $body['start_now'] = null;
        if ($date->isSameDay(now())) {
            $now = CarbonImmutable::now();
            $next = $now->setSecond(0)->addMinutes(5 - ($now->minute % 5));
            $free = $this->availability->freeStaffAt($branch, $selections, $staff, $next, enforceCustomerRules: false);
            if ($free !== []) {
                $body['start_now'] = ['start_time' => $next->format('H:i'), 'end_time' => $next->addMinutes($this->availability->totalDuration($selections))->format('H:i')];
            }
        }

        return $this->ok($body);
    }
}
