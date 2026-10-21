<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AppointmentItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerQrService;
use App\Support\CustomerCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = User::customers()
            ->withCount(['appointments', 'appointments as completed_count' => fn ($q) => $q->where('status', AppointmentStatus::Completed->value)])
            ->withSum(['appointments as lifetime_value' => fn ($q) => $q->where('status', AppointmentStatus::Completed->value)], 'total_amount')
            ->withMax('appointments as last_visit', 'starts_at')
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                // A scanned or typed QR code (any case, with or without dashes) finds its owner exactly.
                $code = CustomerCode::normalize($term);
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('email', $like)->orWhereLike('phone', $like)
                    ->when($code, fn ($q) => $q->orWhere('qr_code', $code)));
            })
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.customers.index', ['customers' => $customers]);
    }

    public function show(User $customer): View
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('view', $customer);

        $customer->load(['customerProfile', 'favorites.favoritable']);

        return view('admin.customers.show', [
            'customer' => $customer,
            'appointments' => $customer->appointments()->with(['items', 'branch', 'staff.user'])->orderByDesc('starts_at')->paginate(self::PER_PAGE),
            'completedServices' => AppointmentItem::query()
                ->whereHas('appointment', fn ($q) => $q->where('customer_id', $customer->id)->where('status', AppointmentStatus::Completed->value))
                ->selectRaw('service_name, COUNT(*) as times, MAX(created_at) as last_at')
                ->groupBy('service_name')->orderByDesc('times')->get(),
            'reviews' => $customer->reviews()->with('service')->latest()->get(),
            'stats' => [
                'total' => $customer->appointments()->count(),
                'completed' => $customer->appointments()->where('status', 'completed')->count(),
                'cancelled' => $customer->appointments()->where('status', 'cancelled')->count(),
                'no_show' => $customer->appointments()->where('status', 'no_show')->count(),
                'value' => (float) $customer->appointments()->where('status', 'completed')->sum('total_amount'),
            ],
        ]);
    }

    /** The customer's QR code image (shown on their page; `?download=1` saves it for printing or sending). */
    public function qr(Request $request, User $customer, CustomerQrService $qr): Response
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('view', $customer);

        return $qr->response($customer, $request, download: $request->boolean('download'));
    }

    public function updateStatus(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($customer->isCustomer(), 404);
        $this->authorize('changeStatus', $customer);
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        $old = $customer->status->value;
        $customer->update(['status' => $data['status']]);
        if ($data['status'] !== UserStatus::Active->value) {
            $customer->tokens()->delete(); // sign out of the app immediately
        }
        $audit->log('customer.status', $customer, ['status' => $old], ['status' => $data['status']]);

        return back()->with('success', 'Customer status updated.');
    }

    /** Super admins put a customer on the test side (seen only by test accounts) or back on live. */
    public function updateAccountType(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('super-admin');
        $data = $request->validate(['account_type' => ['required', Rule::in(['live', 'test'])]]);
        $test = $data['account_type'] === 'test';
        if ($customer->is_test === $test) {
            return back();
        }

        $customer->setTestAccount($test);
        $audit->log('customer.account_type', $customer, ['is_test' => ! $test], ['is_test' => $test]);

        // The customer is on the other side now, so this admin can no longer open their page.
        $side = $test ? 'test' : 'live';

        return redirect()->route('customers.index')
            ->with('success', "{$customer->name} is now a {$side} account. Only {$side} accounts can see them and their bookings.");
    }
}
