@php
    $editing = $promotion->exists;
    // New promotions are a Fixed Price. Older ones keep the type they were made with (Percentage, Special Price, ...):
    // saving one must not quietly turn "20% off" into "₱20".
    $type = old('discount_type', $promotion->discount_type?->value ?? 'fixed');
    $typeLabel = \App\Enums\DiscountType::tryFrom($type)?->label() ?? $type;
    // Services and branches deleted since the promotion was made (they stay linked, so it applies to nothing there).
    $deletedServices = $editing && $promotion->relationLoaded('services') ? $promotion->services->filter->trashed()->pluck('name') : collect();
    $deletedBranches = $editing && $promotion->relationLoaded('branches') ? $promotion->branches->filter->trashed()->pluck('name') : collect();
@endphp
<x-admin.layout :title="$editing ? 'Edit promotion' : 'New promotion'">
    <x-admin.page-header :title="$editing ? 'Edit '.$promotion->name : 'New promotion'" :back="route('promotions.index')"
                         subtitle="Only enter official offers. Saving keeps it as a draft unless you choose Active." />

    <form method="POST" action="{{ $editing ? route('promotions.update', $promotion) : route('promotions.store') }}" enctype="multipart/form-data">
        @csrf @if ($editing) @method('PUT') @endif
        {{-- Test accounts see the shared setup read-only. --}}
        <fieldset class="min-w-0" @cannot('change-setup') disabled @endcannot>
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-admin.card title="Offer">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input name="name" label="Name" :value="$promotion->name" required class="sm:col-span-2" />
                        <x-admin.textarea name="description" label="Description" :value="$promotion->description" rows="3" class="sm:col-span-2" />
                    </div>
                </x-admin.card>
                <x-admin.card title="Price & schedule" subtitle="Set the price customers pay and when this offer is available.">
                    <input type="hidden" name="discount_type" value="{{ $type }}">
                    @if ($type !== 'fixed')
                        <p class="mb-4 rounded-xl bg-surface-container px-4 py-3 text-sm text-on-surface-variant">
                            This promotion was made as <span class="font-medium text-on-surface">{{ $typeLabel }}</span>. Saving keeps it that way.
                        </p>
                    @endif
                    @if ($type === 'percentage')
                        <x-admin.input name="discount_value" type="number" step="0.01" min="0" max="100" label="Percent off (%)" :value="old('discount_value', $promotion->discount_value)" hint="Taken off the price of each eligible service." class="mb-5 max-w-sm" />
                    @elseif ($type === 'special_price')
                        <x-admin.input name="promo_price" type="number" step="0.01" min="0" label="Price" prefix="₱" :value="old('promo_price', $promotion->promo_price)" hint="The customer pays this price for each eligible service." class="mb-5 max-w-sm" />
                    @elseif ($type === 'fixed')
                        <x-admin.input name="discount_value" type="number" step="0.01" min="0" label="Price" prefix="₱" :value="old('discount_value', $promotion->discount_value)" hint="The customer pays this price for each eligible service." class="mb-5 max-w-sm" />
                    @else
                        {{-- Package / Buy X Get Y: redeemed at the salon, no automatic discount. --}}
                        <input type="hidden" name="discount_value" value="{{ old('discount_value', $promotion->discount_value) }}">
                        <p class="mb-5 text-sm text-on-surface-variant">Redeemed at the salon; it takes nothing off automatically when booking.</p>
                    @endif
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input name="start_at" type="datetime-local" label="Starts" :value="old('start_at', $promotion->start_at?->format('Y-m-d\TH:i'))" required />
                        <x-admin.input name="end_at" type="datetime-local" label="Ends" :value="old('end_at', $promotion->end_at?->format('Y-m-d\TH:i'))" required />
                    </div>
                </x-admin.card>
                <x-admin.card title="Terms & conditions" subtitle="Optional details customers should know before booking.">
                    <x-admin.textarea name="terms_and_conditions" label="Terms & conditions" :value="$promotion->terms_and_conditions" rows="3" />
                </x-admin.card>
                <x-admin.card title="Applies to" :subtitle="in_array($type, ['fixed', 'special_price'], true) ? 'Pick the services this price is for. Leave branches empty for all branches.' : 'Leave empty to apply to all services / all branches.'">
                    @if ($deletedServices->isNotEmpty() || $deletedBranches->isNotEmpty())
                        <p class="mb-4 rounded-xl bg-danger-container px-4 py-3 text-sm text-danger" role="alert">
                            Deleted since this promotion was made: {{ $deletedServices->merge($deletedBranches)->join(', ') }}.
                            It no longer applies there{{ $deletedServices->count() === $promotion->services->count() && $deletedServices->isNotEmpty() ? ', so it is not used at all until you pick other services' : '' }}.
                        </p>
                    @endif
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.select name="services[]" label="Services" :options="$services" :value="$promotion->relationLoaded('services') ? $promotion->services->pluck('id')->all() : []" multiple hint="Hold Ctrl/Cmd to select several." />
                        <x-admin.select name="branches[]" label="Branches" :options="$branches" :value="$promotion->relationLoaded('branches') ? $promotion->branches->pluck('id')->all() : []" multiple />
                    </div>
                </x-admin.card>
            </div>
            <div class="space-y-6">
                <x-admin.card title="Publishing">
                    <x-admin.select name="status" label="Status" :options="$statuses" :value="$promotion->status?->value ?? 'draft'" required hint="Draft = hidden. Active = visible during its dates." />
                </x-admin.card>
                <x-admin.card title="Banner image"><x-admin.image-field label="Image (landscape works best)" :current="\App\Support\Fmt::url($promotion->image)" /></x-admin.card>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('promotions.index')" variant="text">@can('change-setup') Cancel @else Back @endcan</x-admin.button>
            @can('change-setup')
                <x-admin.button type="submit">Save &amp; preview</x-admin.button>
            @endcan
        </div>
        </fieldset>
    </form>
</x-admin.layout>
