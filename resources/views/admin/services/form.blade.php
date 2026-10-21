@php
    $editing = $service->exists;
    $variantRows = old('variants', $service->relationLoaded('variants') ? $service->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => $v->price, 'duration_minutes' => $v->duration_minutes, 'description' => $v->description])->values()->all() : []);
    $assignedBranches = $service->relationLoaded('branches') ? $service->branches->keyBy('id') : collect();
    $assignedStaff = old('staff', $service->relationLoaded('staff') ? $service->staff->pluck('id')->all() : []);
    $productRows = old('products', $service->relationLoaded('products') ? $service->products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => (float) $p->pivot->quantity])->values()->all() : []);
    $productCatalog = $products->mapWithKeys(fn ($p) => [$p->id => ['unit' => $p->unit, 'cost' => (float) $p->unit_cost]]);
@endphp
<x-admin.layout :title="$editing ? 'Edit service' : 'New service'">
    <x-admin.page-header :title="$editing ? 'Edit '.$service->name : 'New service'" :back="route('services.index')"
                         subtitle="Prices shown to customers come from here. Do not enter unofficial prices." />

    <form method="POST" action="{{ $editing ? route('services.update', $service) : route('services.store') }}" enctype="multipart/form-data"
          x-data="{ price: @js((float) old('regular_price', $service->regular_price)) }">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-admin.card title="Details">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input name="name" label="Name" :value="$service->name" required class="sm:col-span-2" />
                        <x-admin.select name="category_id" label="Category" :options="$categories" :value="$service->category_id" placeholder="Select a category" required />
                        <x-admin.input name="slug" label="URL slug" :value="$service->slug" hint="Leave blank to generate." />
                        <x-admin.input name="short_description" label="Short description" :value="$service->short_description" hint="Shown on service cards." class="sm:col-span-2" />
                        <x-admin.textarea name="description" label="Full description" :value="$service->description" rows="5" class="sm:col-span-2" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Pricing & timing">
                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-admin.input name="regular_price" type="number" step="0.01" min="0" label="Regular price" prefix="₱" :value="$service->regular_price" required x-model.number="price" />
                        <x-admin.input name="duration_minutes" type="number" min="5" step="5" label="Duration (min)" :value="$service->duration_minutes" required />
                        <x-admin.input name="buffer_minutes" type="number" min="0" step="5" label="Buffer after (min)" :value="$service->buffer_minutes" hint="Clean-up time; blocks the staff member." />
                    </div>
                </x-admin.card>

                <x-admin.card title="Options / variants" subtitle="e.g. Short, Medium, Long hair — each with its own price and duration.">
                    <div x-data="{ rows: @js(array_values($variantRows)) }">
                        <template x-for="(row, i) in rows" :key="i">
                            <div class="mb-3 grid gap-3 rounded-2xl bg-surface-container p-3 sm:grid-cols-12">
                                <input type="hidden" :name="`variants[${i}][id]`" :value="row.id ?? ''">
                                <div class="sm:col-span-4"><label class="label" :for="`v_name_${i}`">Option name</label><input :id="`v_name_${i}`" class="field" :name="`variants[${i}][name]`" x-model="row.name" required></div>
                                <div class="sm:col-span-3"><label class="label" :for="`v_price_${i}`">Price (₱)</label><input :id="`v_price_${i}`" class="field" type="number" step="0.01" min="0" :name="`variants[${i}][price]`" x-model="row.price" required></div>
                                <div class="sm:col-span-3"><label class="label" :for="`v_dur_${i}`">Duration (min)</label><input :id="`v_dur_${i}`" class="field" type="number" min="5" step="5" :name="`variants[${i}][duration_minutes]`" x-model="row.duration_minutes" placeholder="Same as service"></div>
                                <div class="flex items-end sm:col-span-2"><button type="button" class="inline-flex min-h-11 w-full items-center justify-center gap-1 rounded-full text-sm font-medium text-danger hover:bg-danger-container/60" @click="rows.splice(i, 1)"><span class="material-symbols-outlined" aria-hidden="true">delete</span> Remove</button></div>
                            </div>
                        </template>
                        <p x-show="rows.length === 0" class="mb-3 text-sm text-on-surface-variant">No options — customers book the service at its regular price.</p>
                        @error('variants.*.name')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        @error('variants.*.price')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        <x-admin.button variant="tonal" icon="add" size="sm" @click="rows.push({ name: '', price: '', duration_minutes: '' })">Add option</x-admin.button>
                    </div>
                </x-admin.card>

                <x-admin.card title="Products used" id="products" subtitle="Inventory used up each time this service is done. Its cost is taken off the price to give net revenue.">
                    @if ($products->isEmpty())
                        <p class="text-sm text-on-surface-variant">No products in the inventory yet. <a href="{{ route('products.create') }}" class="font-medium text-primary hover:underline">Add a product</a>, then come back to choose it here.</p>
                    @else
                        <div x-data="{
                                catalog: @js($productCatalog),
                                rows: @js(array_values($productRows)),
                                lineCost(row) { const p = this.catalog[row.product_id]; return p ? p.cost * (Number(row.quantity) || 0) : 0 },
                                total() { return this.rows.reduce((sum, row) => sum + this.lineCost(row), 0) },
                                peso(v) { return (v < 0 ? '-' : '') + '₱' + Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                             }">
                            <template x-for="(row, i) in rows" :key="i">
                                <div class="mb-3 grid gap-3 rounded-2xl bg-surface-container p-3 sm:grid-cols-12">
                                    <div class="sm:col-span-5">
                                        <label class="label" :for="`p_id_${i}`">Product</label>
                                        <select :id="`p_id_${i}`" class="field" :name="`products[${i}][product_id]`" x-model="row.product_id" required>
                                            <option value="">Select a product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->label() }} — ₱{{ number_format($product->unit_cost, 2) }}/{{ $product->unit }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="label" :for="`p_qty_${i}`">Used per service</label>
                                        <div class="relative">
                                            <input :id="`p_qty_${i}`" class="field pr-16" type="number" step="0.01" min="0.01" :name="`products[${i}][quantity]`" x-model="row.quantity" required>
                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-sm text-on-surface-variant" x-text="catalog[row.product_id]?.unit ?? ''"></span>
                                        </div>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <p class="label">Cost</p>
                                        <p class="flex min-h-11 items-center font-medium" x-text="peso(lineCost(row))"></p>
                                    </div>
                                    <div class="flex items-end sm:col-span-2"><button type="button" class="inline-flex min-h-11 w-full items-center justify-center gap-1 rounded-full text-sm font-medium text-danger hover:bg-danger-container/60" @click="rows.splice(i, 1)"><span class="material-symbols-outlined" aria-hidden="true">delete</span> Remove</button></div>
                                </div>
                            </template>
                            <p x-show="rows.length === 0" class="mb-3 text-sm text-on-surface-variant">No products — the whole price counts as net revenue.</p>
                            @error('products.*.product_id')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                            @error('products.*.quantity')<p class="mb-2 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <x-admin.button variant="tonal" icon="add" size="sm" @click="rows.push({ product_id: '', quantity: 1 })">Add product</x-admin.button>
                                <dl x-show="rows.length > 0" class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                    <div class="flex gap-1.5"><dt class="text-on-surface-variant">Product cost</dt><dd class="font-semibold" x-text="peso(total())"></dd></div>
                                    <div class="flex gap-1.5"><dt class="text-on-surface-variant">Net at regular price</dt><dd class="font-semibold" :class="(Number(price) || 0) - total() < 0 && 'text-danger'" x-text="peso((Number(price) || 0) - total())"></dd></div>
                                </dl>
                            </div>
                        </div>
                    @endif
                </x-admin.card>

                <x-admin.card title="Branches" subtitle="Where this service can be booked. Leave the price blank to use the regular price." :padding="false">
                    <x-admin.table :headers="['Offered at', 'Branch price (optional)']" :wide="['Branch price (optional)']">
                        @foreach ($branches as $branch)
                            @php $pivot = $assignedBranches->get($branch->id)?->pivot; $enabled = old("branches.{$branch->id}.enabled", $pivot ? true : ! $editing); @endphp
                            <tr>
                                <td>
                                    <input type="hidden" name="branches[{{ $branch->id }}][enabled]" value="0">
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 font-medium">
                                        <input type="checkbox" id="b_{{ $branch->id }}" name="branches[{{ $branch->id }}][enabled]" value="1" @checked($enabled) class="h-5 w-5 shrink-0 accent-[var(--color-primary)]">
                                        {{ $branch->name }}
                                    </label>
                                </td>
                                <td class="w-56"><x-admin.input :name="'branches['.$branch->id.'][custom_price]'" type="number" step="0.01" min="0" prefix="₱" :value="$pivot?->custom_price" aria-label="Price at {{ $branch->name }}" /></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                </x-admin.card>
            </div>

            <div class="space-y-6">
                <x-admin.card title="Visibility">
                    <div class="space-y-4">
                        <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="$service->status?->value ?? 'active'" required />
                        <x-admin.checkbox name="is_featured" label="Featured on the home screen" :checked="$service->is_featured" />
                        <x-admin.input name="sort_order" type="number" min="0" label="Display order" :value="$service->sort_order ?? 0" />
                    </div>
                </x-admin.card>
                <x-admin.card title="Image"><x-admin.image-field label="Service photo" :current="\App\Support\Fmt::url($service->image)" /></x-admin.card>
                <x-admin.card title="GCash downpayment" id="gcash"
                              subtitle="Optional. Customers who book this service pay this amount first by scanning this QR. The rest is paid at the salon.">
                    <div class="space-y-4">
                        <x-admin.input name="deposit_amount" type="number" step="0.01" min="1" label="Downpayment amount" prefix="₱" :value="$service->deposit_amount"
                                       hint="Must be exactly the amount you set in the GCash QR." />
                        <x-admin.image-field name="gcash_qr" label="GCash QR for this amount" :current="\App\Support\Fmt::url($service->gcash_qr)" />
                        <p class="text-xs text-on-surface-variant">In the GCash app, generate a QR to receive money with this exact amount, save it, and upload it here. It is stored without compression so it stays scannable.</p>
                        @if ($service->gcash_qr)
                            <x-admin.checkbox name="remove_gcash_qr" label="Remove the QR" hint="Customers will no longer pay a downpayment for this service." />
                        @endif
                    </div>
                </x-admin.card>
                <x-admin.card title="Staff who perform it">
                    <div class="max-h-80 space-y-1 overflow-y-auto">
                        @forelse ($staffList as $member)
                            <x-admin.checkbox name="staff[]" :value="$member->id" :label="$member->user?->name ?? 'Staff'" :hint="$member->position" :checked="in_array($member->id, $assignedStaff, true)" />
                        @empty
                            <p class="text-sm text-on-surface-variant">No staff yet.</p>
                        @endforelse
                    </div>
                </x-admin.card>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('services.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Create service' }}</x-admin.button>
        </div>
    </form>
</x-admin.layout>
