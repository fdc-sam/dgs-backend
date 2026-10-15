<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AppointmentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Admin\AdminCustomerResource;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Support\CustomerCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer look-up for the admin app. The main entry is the QR code: scanning a customer's code returns who they
 * are and everything the desk needs next (today's appointments, what is coming, recent visits).
 */
class CustomerController extends ApiController
{
    /** Scanned (or typed) QR code → the customer and their appointments. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        $code = CustomerCode::normalize($data['code']);
        if (! $code) {
            throw ApiException::validation('That is not a DGS customer code.', ['code' => ['That is not a DGS customer code.']]);
        }

        $customer = User::customers()->where('qr_code', $code)->with('customerProfile')->first()
            ?? throw ApiException::notFound('No customer found for this code.');

        return $this->ok($this->payload($request, $customer));
    }

    public function show(Request $request, User $customer): JsonResponse
    {
        abort_unless($customer->isCustomer(), 404);

        return $this->ok($this->payload($request, $customer->load('customerProfile')));
    }

    /** Search the client list (managers only). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $customers = User::customers()->with('customerProfile')
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $code = CustomerCode::normalize($term);
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('phone', $like)
                    ->when($code, fn ($q) => $q->orWhere('qr_code', $code)));
            })
            ->orderBy('name')
            ->paginate($this->perPage(20));

        return $this->ok(AdminCustomerResource::collection($customers));
    }

    private function payload(Request $request, User $customer): array
    {
        $actor = $request->user();
        $today = now()->toDateString();

        // Staff (stylists) only ever see appointments assigned to them; managers see everything.
        $appointments = fn () => Appointment::query()
            ->where('customer_id', $customer->id)
            ->when(! $actor->isManager(), fn ($q) => $q->where('staff_profile_id', $actor->staffProfile?->id ?? ''))
            ->with(['customer', 'branch', 'staff.user', 'items', 'promotion', 'review']);

        $resolve = fn ($collection) => AppointmentResource::collection($collection)->resolve($request);

        return [
            'customer' => (new AdminCustomerResource($customer))->resolve($request),
            'today' => $resolve($appointments()->whereDate('appointment_date', $today)->orderBy('start_time')->get()),
            'upcoming' => $resolve($appointments()->whereDate('appointment_date', '>', $today)
                ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled]))
                ->orderBy('starts_at')->limit(5)->get()),
            'recent' => $resolve($appointments()->whereDate('appointment_date', '<', $today)->orderByDesc('starts_at')->limit(5)->get()),
            'can_walk_in' => $actor->can('createForCustomer', Appointment::class) && $customer->isActive(),
        ];
    }
}
