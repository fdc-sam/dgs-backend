<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\ReferralStatus;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Customer referrals: check whether the referred person completed a service, then pay the referrer through GCash. */
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', ReferralStatus::Pending->value);
        $referrals = Referral::query()->with(['referrer', 'services'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->query('search'), function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('phone', $like)
                    ->orWhereHas('referrer', fn ($r) => $r->whereLike('name', $like)->orWhereLike('phone', $like)));
            })
            ->latest()->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.referrals.index', [
            'referrals' => $referrals,
            'status' => $status,
            'counts' => Referral::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'visits' => $this->visitsByPhone($referrals->pluck('phone')->unique()->values()->all()),
        ]);
    }

    public function status(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, ReferralStatus::manual()))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($referral->status === ReferralStatus::Completed) {
            return back()->with('error', 'This referral is already completed and its reward was sent.');
        }

        $status = ReferralStatus::from($data['status']);
        $this->referrals->setStatus($referral, $status, $data['note'] ?? null);
        $notified = $status === ReferralStatus::Pending ? '' : " {$referral->referrer?->name} has been notified.";

        return back()->with('success', "{$referral->name}: {$status->label()}.{$notified}");
    }

    /** Completing records the GCash reward the owner sent; the referrer is told to check their GCash. */
    public function complete(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (in_array($referral->status, [ReferralStatus::Completed, ReferralStatus::Declined], true)) {
            return back()->with('error', $referral->status === ReferralStatus::Completed
                ? 'This referral is already completed and its reward was sent.'
                : 'Reopen this referral before completing it.');
        }

        $this->referrals->complete($referral, (float) $data['amount'], $data['reference'] ?? null, $data['note'] ?? null);

        return back()->with('success', "Referral completed. {$referral->referrer?->name} has been notified to check their GCash.");
    }

    /**
     * What the salon knows about each referred number: the customer account using it (if any) and how many completed
     * visits it has, booked in the app or recorded as a walk-in.
     *
     * @param  list<string>  $phones
     * @return array<string, array{customer: ?User, completed: int}>
     */
    private function visitsByPhone(array $phones): array
    {
        if ($phones === []) {
            return [];
        }

        $customers = User::customers()->whereIn('phone', $phones)
            ->withCount(['appointments as completed_count' => fn ($q) => $q->where('status', AppointmentStatus::Completed->value)])
            ->get()->keyBy('phone');
        // Walk-in phones are stored as typed, so ignore spaces and dashes when matching.
        $walkIns = WalkInVisit::query()->whereNotNull('completed_at')
            ->whereIn(DB::raw("REPLACE(REPLACE(phone, ' ', ''), '-', '')"), $phones)
            ->selectRaw("REPLACE(REPLACE(phone, ' ', ''), '-', '') as number, COUNT(*) as c")
            ->groupBy('number')->pluck('c', 'number');

        $visits = [];
        foreach ($phones as $phone) {
            $customer = $customers->get($phone);
            $visits[$phone] = [
                'customer' => $customer,
                'completed' => (int) ($customer?->completed_count ?? 0) + (int) ($walkIns[$phone] ?? 0),
            ];
        }

        return $visits;
    }
}
