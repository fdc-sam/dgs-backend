<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read-side queries for the public service catalog. */
class ServiceCatalogService
{
    /**
     * @param  array{category_id?: ?string, branch_id?: ?string, search?: ?string, featured?: mixed}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $branchId = $filters['branch_id'] ?? null;

        return Service::query()
            ->active()
            ->whereHas('category', fn (Builder $q) => $q->where('status', RecordStatus::Active->value))
            ->with(['category', 'variants' => fn ($q) => $q->active()->orderBy('price')])
            ->withAvg('approvedReviews', 'rating')
            ->withCount('approvedReviews')
            ->when($branchId, fn (Builder $q) => $this->forBranch($q, $branchId))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $q) => $q->whereLike('name', $term)
                    ->orWhereLike('short_description', $term));
            })
            ->when(filter_var($filters['featured'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $q) => $q->where('is_featured', true))
            ->ordered();
    }

    /** Restrict to services available at the branch and expose its custom price as branch_price. */
    public function forBranch(Builder $query, string $branchId): Builder
    {
        return $this->withBranchPrice($query->availableAtBranch($branchId), $branchId);
    }

    public function withBranchPrice(Builder $query, string $branchId): Builder
    {
        return $query->select('services.*')
            ->addSelect(['branch_price' => DB::table('branch_service')
                ->select('custom_price')
                ->whereColumn('branch_service.service_id', 'services.id')
                ->where('branch_service.branch_id', $branchId)
                ->limit(1)]);
    }

    public function loadDetail(Service $service, ?string $branchId = null): Service
    {
        $query = Service::query()->whereKey($service->id)
            ->with([
                'category',
                'variants' => fn ($q) => $q->active()->orderBy('price'),
                'branches' => fn ($q) => $q->active()->wherePivot('is_available', true),
                'staff' => fn ($q) => $q->bookable()->with('user'),
            ])
            ->withAvg('approvedReviews', 'rating')
            ->withCount('approvedReviews');

        if ($branchId) {
            $this->withBranchPrice($query, $branchId);
        }

        return $query->firstOrFail();
    }
}
