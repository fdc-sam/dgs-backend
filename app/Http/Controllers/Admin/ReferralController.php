<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\ReferralStatus;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralItem;
use App\Models\Service;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Services\LoyaltyProgram;
use App\Services\ReferralService;
use App\Services\AuditLogger;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Customer referrals: check whether the referred person completed a service, then add the referrer's reward to their earnings. */
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function index(Request $request, LoyaltyProgram $loyalty): View
    {
        $status = $request->query('status', ReferralStatus::Pending->value);
        $referrals = Referral::query()->with(['referrer', 'services.category'])
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
            // The Complete form starts with the reward set in Settings for the referral's services.
            'suggestions' => $referrals->getCollection()->mapWithKeys(fn ($r) => [$r->id => $loyalty->suggestedReward($r)]),
            'services' => Service::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Referral $referral, LoyaltyProgram $loyalty): View
    {
        $referral->load(['referrer', 'services.category', 'items.service', 'items.staff.user']);
        $visit = $this->visitsByPhone([$referral->phone])[$referral->phone] ?? ['customer' => null, 'completed' => 0];

        return view('admin.referrals.show', [
            'referral' => $referral,
            'visit' => $visit,
            'suggestion' => $loyalty->suggestedReward($referral),
            'services' => Service::query()->active()->orderBy('name')->get(['id', 'name']),
            'staff' => \App\Models\StaffProfile::bookable()->with(['user', 'services'])->get()
                ->sortBy(fn ($profile) => $profile->user?->name)->values(),
        ]);
    }

    public function storeItem(Request $request, Referral $referral, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isManager() || $request->user()->isCashier(), 403);
        $data = $request->validate([
            'item_type' => ['required', Rule::in(['service', 'custom'])],
            'service_id' => ['nullable', 'required_if:item_type,service', 'string', 'exists:services,id'],
            'staff_profile_id' => ['nullable', 'string', 'exists:staff_profiles,id'],
            'service_name' => ['nullable', 'required_if:item_type,custom', 'string', 'max:255'],
            'unit_price' => ['nullable', 'required_if:item_type,custom', 'numeric', 'min:0.01', 'max:99999999.99'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $service = null;
        $stylist = null;
        if ($data['item_type'] === 'service') {
            $service = Service::query()->active()->find($data['service_id']);
            if (! $service) {
                return back()->withInput()->withErrors(['service_id' => 'Choose an active service.']);
            }
            if (! empty($data['staff_profile_id'])) {
                $stylist = \App\Models\StaffProfile::bookable()->offersService($service->id)->find($data['staff_profile_id']);
                if (! $stylist) {
                    return back()->withInput()->withErrors(['staff_profile_id' => 'Choose a stylist who offers this service.']);
                }
            }
            $name = $service->name;
            $price = number_format((float) $service->regular_price, 2, '.', '');
        } else {
            $name = trim($data['service_name']);
            $price = number_format((float) $data['unit_price'], 2, '.', '');
        }

        $item = DB::transaction(function () use ($referral, $service, $stylist, $name, $price, $data) {
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->id);
            $this->ensureReferralEditable($locked);
            $item = $locked->items()->create([
                'service_id' => $service?->id,
                'staff_profile_id' => $stylist?->id,
                'service_name' => $name,
                'quantity' => (int) $data['quantity'],
                'unit_price' => $price,
                'subtotal' => number_format((float) $price * (int) $data['quantity'], 2, '.', ''),
            ]);
            $this->syncReferralServices($locked);

            return $item;
        });

        $audit->log('referral.item.added', $referral, null, [
            'item_id' => $item->id, 'service_id' => $item->service_id, 'service_name' => $item->service_name,
            'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'subtotal' => $item->subtotal,
        ]);

        return back()->with('success', 'Service or charge added to the referral.');
    }

    public function updateItem(Request $request, Referral $referral, ReferralItem $item, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $item = $referral->items()->whereKey($item->id)->firstOrFail();
        $data = $request->validate([
            'service_id' => ['required', 'string', 'exists:services,id'],
            'staff_profile_id' => ['nullable', 'string', 'exists:staff_profiles,id'],
        ]);
        $service = Service::query()->active()->find($data['service_id']);
        if (! $service) {
            return back()->withInput()->withErrors(['service_id' => 'Choose an active service.']);
        }
        $stylist = null;
        if (! empty($data['staff_profile_id'])) {
            $stylist = \App\Models\StaffProfile::bookable()->offersService($service->id)->find($data['staff_profile_id']);
            if (! $stylist) {
                return back()->withInput()->withErrors(['staff_profile_id' => 'Choose a stylist who offers this service.']);
            }
        }
        $this->ensureReferralEditable($referral);
        $old = ['service_name' => $item->service_name, 'service_id' => $item->service_id, 'subtotal' => $item->subtotal];
        $item->update([
            'service_id' => $service->id,
            'staff_profile_id' => $stylist?->id,
            'service_name' => $service->name,
            'unit_price' => $service->regular_price,
            'subtotal' => number_format((float) $service->regular_price * $item->quantity, 2, '.', ''),
        ]);
        $this->syncReferralServices($referral);
        $audit->log('referral.item.updated', $referral, $old, ['service_id' => $service->id, 'service_name' => $service->name, 'subtotal' => $item->subtotal]);

        return back()->with('success', 'Referral service updated.');
    }

    public function destroyItem(Request $request, Referral $referral, ReferralItem $item, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $item = $referral->items()->whereKey($item->id)->firstOrFail();
        $this->ensureReferralEditable($referral);
        $old = ['service_id' => $item->service_id, 'service_name' => $item->service_name, 'subtotal' => $item->subtotal];
        DB::transaction(function () use ($referral, $item) {
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->id);
            $this->ensureReferralEditable($locked);
            $item->delete();
            $this->syncReferralServices($locked);
        });
        $audit->log('referral.item.removed', $referral, $old);

        return back()->with('success', 'Service or charge removed from the referral.');
    }

    private function ensureReferralEditable(Referral $referral): void
    {
        if (in_array($referral->status, [ReferralStatus::Completed, ReferralStatus::Declined], true)) {
            throw ValidationException::withMessages(['item_type' => 'Services and charges cannot be changed for a closed referral.']);
        }
    }

    private function syncReferralServices(Referral $referral): void
    {
        $serviceIds = $referral->items()->whereNotNull('service_id')->pluck('service_id')->unique()->values()->all();
        $referral->services()->sync($serviceIds);
    }

    public function status(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, ReferralStatus::manual()))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($referral->status === ReferralStatus::Completed) {
            return back()->with('error', 'This referral is already completed and its reward was given.');
        }

        $status = ReferralStatus::from($data['status']);
        $this->referrals->setStatus($referral, $status, $data['note'] ?? null);
        $notified = $status === ReferralStatus::Pending ? '' : " {$referral->referrer?->name} has been notified.";

        return back()->with('success', "{$referral->name}: {$status->label()}.{$notified}");
    }

    /** Completing adds the reward to the referrer's in-app earnings; they cash it out from the app (Cash-outs). */
    public function complete(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (in_array($referral->status, [ReferralStatus::Completed, ReferralStatus::Declined], true)) {
            return back()->with('error', $referral->status === ReferralStatus::Completed
                ? 'This referral is already completed and its reward was given.'
                : 'Reopen this referral before completing it.');
        }

        $this->referrals->complete($referral, (float) $data['amount'], $data['note'] ?? null);

        return back()->with('success', 'Referral completed. '.Fmt::peso($data['amount'])." was added to {$referral->referrer?->name}'s earnings and they have been notified.");
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
