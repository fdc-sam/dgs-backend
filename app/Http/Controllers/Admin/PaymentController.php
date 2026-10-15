<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Services\FileUploadService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GCash deposit verification: the receipt queue plus approve / reject / waive / refund on an appointment. */
class PaymentController extends Controller
{
    /** Tabs on the payments page, in the order staff work through them. */
    public const TABS = [
        PaymentStatus::Submitted, PaymentStatus::Awaiting, PaymentStatus::Rejected, PaymentStatus::Paid,
        PaymentStatus::Waived, PaymentStatus::Expired, PaymentStatus::Refunded,
    ];

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn (PaymentStatus $s) => $s->value, self::TABS))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = PaymentStatus::from($data['status'] ?? PaymentStatus::Submitted->value);

        $appointments = Appointment::query()
            ->with(['customer', 'branch', 'latestPayment'])
            ->where('payment_status', $status->value)
            ->when($data['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->whereLike('appointment_number', $like)
                    ->orWhereHas('payments', fn ($p) => $p->whereLike('reference_number', $like))
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)));
            })
            // Oldest receipt / nearest deadline first so nobody waits too long; history newest first.
            ->when($status === PaymentStatus::Submitted, fn ($q) => $q->orderBy('updated_at'))
            ->when($status->isHolding(), fn ($q) => $q->orderBy('payment_due_at'))
            ->when($status !== PaymentStatus::Submitted && ! $status->isHolding(), fn ($q) => $q->orderByDesc('updated_at'))
            ->paginate(20)->withQueryString();

        $counts = Appointment::query()
            ->whereIn('payment_status', array_map(fn (PaymentStatus $s) => $s->value, self::TABS))
            ->selectRaw('payment_status, count(*) as total')->groupBy('payment_status')
            ->pluck('total', 'payment_status');

        return view('admin.payments.index', [
            'appointments' => $appointments,
            'status' => $status,
            'tabs' => self::TABS,
            'counts' => $counts,
            'enabled' => $this->payments->isActive(),
        ]);
    }

    /** The receipt screenshot, streamed from the private disk to staff only. */
    public function proof(Payment $payment): StreamedResponse
    {
        $this->authorize('reviewPayment', $payment->appointment);
        abort_unless($payment->proof_path && Storage::disk(FileUploadService::privateDisk())->exists($payment->proof_path), 404);

        return Storage::disk(FileUploadService::privateDisk())->response($payment->proof_path, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('reviewPayment', $appointment);
        if (is_string($request->input('reference_number'))) {
            $request->merge(['reference_number' => PaymentService::normalizeReference($request->input('reference_number')) ?: null]);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'reference_number' => ['nullable', 'string', 'regex:/^[A-Z0-9]{6,30}$/'],
        ], ['reference_number.regex' => 'Enter the GCash Ref No. (letters and digits only).']);

        return $this->attempt(
            fn () => $this->payments->approve($appointment, $request->user(), round((float) $data['amount'], 2), $data['reference_number'] ?? null),
            'Payment approved. The customer has been notified.',
        );
    }

    public function reject(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('reviewPayment', $appointment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return $this->attempt(
            fn () => $this->payments->reject($appointment, $request->user(), trim($data['reason'])),
            'Receipt rejected. The customer can send a new one.',
        );
    }

    public function waive(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('reviewPayment', $appointment);

        return $this->attempt(fn () => $this->payments->waive($appointment, $request->user()), 'Deposit waived.');
    }

    public function refund(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('reviewPayment', $appointment);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(
            fn () => $this->payments->markRefunded($appointment, $request->user(), $data['note'] ?? null),
            'Deposit marked as refunded. The customer has been notified.',
        );
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
