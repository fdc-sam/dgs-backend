<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DiscountType;
use App\Enums\NotificationType;
use App\Enums\PromotionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Branch;
use App\Models\Promotion;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Services\CatalogAdminService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function __construct(private readonly CatalogAdminService $catalog) {}

    public function index(Request $request): View
    {
        $now = now();
        $promotions = Promotion::query()->withCount('services')
            ->when($request->query('state') === 'live', fn ($q) => $q->currentlyActive())
            ->when($request->query('state') === 'draft', fn ($q) => $q->where('status', PromotionStatus::Draft->value))
            ->when($request->query('state') === 'expired', fn ($q) => $q->where('end_at', '<', $now))
            ->latest('start_at')
            ->paginate(20)->withQueryString();

        return view('admin.promotions.index', ['promotions' => $promotions]);
    }

    public function create(): View
    {
        return view('admin.promotions.form', $this->formData(new Promotion([
            'status' => PromotionStatus::Draft, 'discount_type' => DiscountType::Percentage,
            'start_at' => now()->startOfDay(), 'end_at' => now()->addMonth()->endOfDay(),
        ])));
    }

    public function store(PromotionRequest $request): RedirectResponse
    {
        $promotion = $this->catalog->savePromotion(null, $request->validated(), $request->file('image'));

        return redirect()->route('promotions.preview', $promotion)->with('success', 'Promotion saved. Review the preview before publishing.');
    }

    public function edit(Promotion $promotion): View
    {
        return view('admin.promotions.form', $this->formData($promotion->load(['services', 'branches'])));
    }

    public function update(PromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        $this->catalog->savePromotion($promotion, $request->validated(), $request->file('image'));

        return redirect()->route('promotions.preview', $promotion)->with('success', 'Promotion updated.');
    }

    /** Shows the promotion exactly as the mobile app will receive it. */
    public function preview(Promotion $promotion): View
    {
        $promotion->load(['services', 'branches']);

        return view('admin.promotions.preview', [
            'promotion' => $promotion,
            'payload' => (new PromotionResource($promotion))->resolve(request()),
        ]);
    }

    public function publish(Request $request, Promotion $promotion, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['notify' => ['nullable', 'boolean']]);
        $promotion->update(['status' => PromotionStatus::Active]);
        $audit->log('promotion.published', $promotion, null, ['status' => 'active']);

        $message = 'Promotion published.';
        if ($request->boolean('notify') && $promotion->isCurrentlyActive()) {
            $count = $notifications->broadcastToCustomers(NotificationType::Promotion, $promotion->name,
                Str::limit($promotion->description ?: 'A new promotion is available!', 140),
                ['promotion_id' => $promotion->id]);
            $message .= " {$count} customers notified.";
        }

        return redirect()->route('promotions.index')->with('success', $message);
    }

    public function destroy(Promotion $promotion, AuditLogger $audit): RedirectResponse
    {
        $audit->log('promotion.deleted', $promotion, ['name' => $promotion->name]);
        $promotion->update(['slug' => $promotion->slug.'-deleted-'.now()->timestamp]);
        $promotion->delete();

        return redirect()->route('promotions.index')->with('success', 'Promotion deleted.');
    }

    private function formData(Promotion $promotion): array
    {
        return [
            'promotion' => $promotion,
            'services' => Service::orderBy('name')->pluck('name', 'id'),
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'types' => collect(DiscountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]),
            'statuses' => collect(PromotionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
        ];
    }
}
