<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Write-side operations for services, categories and promotions in the admin. */
class CatalogAdminService
{
    public function __construct(
        private readonly FileUploadService $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function saveCategory(?ServiceCategory $category, array $data, ?UploadedFile $image): ServiceCategory
    {
        $category ??= new ServiceCategory;
        $original = $category->getAttributes();

        $category->fill([
            'name' => $data['name'],
            'slug' => $this->slug($data['slug'] ?? null, $data['name'], ServiceCategory::class, $category->id),
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => $data['status'],
        ]);
        if ($image) {
            $category->image = $this->uploads->storeImage($image, 'categories', $category->image);
        }
        $category->save();
        $this->audit->changes($category->wasRecentlyCreated ? 'category.created' : 'category.updated', $category, $original);

        return $category;
    }

    public function saveService(?Service $service, array $data, ?UploadedFile $image, ?UploadedFile $gcashQr = null): Service
    {
        return DB::transaction(function () use ($service, $data, $image, $gcashQr) {
            $service ??= new Service;
            $original = $service->getAttributes();

            $service->fill([
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'slug' => $this->slug($data['slug'] ?? null, $data['name'], Service::class, $service->id),
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'regular_price' => $data['regular_price'],
                'deposit_amount' => ($data['deposit_amount'] ?? '') === '' ? null : $data['deposit_amount'],
                'duration_minutes' => $data['duration_minutes'],
                'buffer_minutes' => $data['buffer_minutes'] ?? 0,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'],
            ]);
            if ($image) {
                $service->image = $this->uploads->storeImage($image, 'services', $service->image);
            }
            if (! empty($data['remove_gcash_qr'])) {
                $this->uploads->delete($service->gcash_qr);
                $service->gcash_qr = null;
                $service->deposit_amount = null;
            } elseif ($gcashQr) {
                // Lossless so the GCash QR stays scannable.
                $service->gcash_qr = $this->uploads->storeImage($gcashQr, 'payments', $service->gcash_qr, lossless: true);
            }
            $service->save();

            $branches = collect($data['branches'] ?? [])
                ->filter(fn ($row) => ! empty($row['enabled']))
                ->mapWithKeys(fn ($row, $branchId) => [$branchId => [
                    'custom_price' => ($row['custom_price'] ?? '') === '' ? null : $row['custom_price'],
                    'is_available' => true,
                ]]);
            $service->branches()->sync($branches->all());
            $service->staff()->sync($data['staff'] ?? []);
            $service->products()->sync(collect($data['products'] ?? [])
                ->mapWithKeys(fn ($row) => [$row['product_id'] => ['quantity' => $row['quantity']]])->all());

            $this->syncVariants($service, $data['variants'] ?? []);

            $this->audit->changes($service->wasRecentlyCreated ? 'service.created' : 'service.updated', $service, $original);

            return $service;
        });
    }

    public function deleteService(Service $service): void
    {
        $this->audit->log('service.deleted', $service, ['name' => $service->name]);
        // Soft delete keeps appointment history intact; slug is freed for reuse.
        $service->update(['slug' => $service->slug.'-deleted-'.Str::lower(Str::random(6))]);
        $service->delete();
    }

    public function savePromotion(?Promotion $promotion, array $data, ?UploadedFile $image): Promotion
    {
        return DB::transaction(function () use ($promotion, $data, $image) {
            $promotion ??= new Promotion;
            $original = $promotion->getAttributes();

            $promotion->fill([
                'name' => $data['name'],
                'slug' => $this->slug($data['slug'] ?? null, $data['name'], Promotion::class, $promotion->id),
                'description' => $data['description'] ?? null,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'] ?? null,
                'promo_price' => $data['promo_price'] ?? null,
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'],
                'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
                'status' => $data['status'],
            ]);
            if ($image) {
                $promotion->image = $this->uploads->storeImage($image, 'promotions', $promotion->image);
            }
            $promotion->save();
            $promotion->services()->sync($data['services'] ?? []);
            $promotion->branches()->sync($data['branches'] ?? []);

            $this->audit->changes($promotion->wasRecentlyCreated ? 'promotion.created' : 'promotion.updated', $promotion, $original);

            return $promotion;
        });
    }

    private function syncVariants(Service $service, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }
            $attributes = [
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'price' => $row['price'],
                'duration_minutes' => ($row['duration_minutes'] ?? '') === '' ? null : $row['duration_minutes'],
                'status' => 'active',
            ];
            $variant = ! empty($row['id']) ? $service->variants()->find($row['id']) : null;
            $variant ? $variant->update($attributes) : $variant = $service->variants()->create($attributes);
            $keep[] = $variant->id;
        }

        // Soft-delete removed options so past appointments keep their references.
        $service->variants()->whereNotIn('id', $keep)->get()->each->delete();
    }

    private function slug(?string $requested, string $name, string $model, ?string $ignoreId): string
    {
        $base = Str::slug($requested ?: $name) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;
        while ($model::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
