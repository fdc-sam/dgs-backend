@php $editing = $promotion->exists; @endphp
<x-admin.layout :title="$editing ? 'Edit promotion' : 'New promotion'">
    <x-admin.page-header :title="$editing ? 'Edit '.$promotion->name : 'New promotion'" :back="route('promotions.index')"
                         subtitle="Only enter official offers. Saving keeps it as a draft unless you choose Active." />

    <form method="POST" action="{{ $editing ? route('promotions.update', $promotion) : route('promotions.store') }}" enctype="multipart/form-data"
          x-data="{ type: @js(old('discount_type', $promotion->discount_type?->value)) }">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-admin.card title="Offer">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input name="name" label="Name" :value="$promotion->name" required class="sm:col-span-2" />
                        <x-admin.textarea name="description" label="Description" :value="$promotion->description" rows="3" class="sm:col-span-2" />
                        <x-admin.select name="discount_type" label="Discount type" :options="$types" :value="$promotion->discount_type?->value" required x-model="type" />
                        <div x-show="type === 'percentage' || type === 'fixed'">
                            <x-admin.input name="discount_value" type="number" step="0.01" min="0" label="Discount value" :value="$promotion->discount_value" hint="Percent (0–100) or peso amount." />
                        </div>
                        <div x-show="type === 'special_price' || type === 'package'">
                            <x-admin.input name="promo_price" type="number" step="0.01" min="0" label="Promo price" prefix="₱" :value="$promotion->promo_price" />
                        </div>
                        <p x-show="type === 'package' || type === 'buy_x_get_y'" class="text-sm text-on-surface-variant sm:col-span-2">
                            This type is shown in the app but applied by staff at the salon (no automatic discount on bookings).
                        </p>
                        <x-admin.input name="start_at" type="datetime-local" label="Starts" :value="$promotion->start_at?->format('Y-m-d\TH:i')" required />
                        <x-admin.input name="end_at" type="datetime-local" label="Ends" :value="$promotion->end_at?->format('Y-m-d\TH:i')" required />
                        <x-admin.textarea name="terms_and_conditions" label="Terms & conditions" :value="$promotion->terms_and_conditions" rows="4" class="sm:col-span-2" />
                    </div>
                </x-admin.card>
                <x-admin.card title="Applies to" subtitle="Leave empty to apply to all services / all branches.">
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
            <x-admin.button :href="route('promotions.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="visibility">Save & preview</x-admin.button>
        </div>
    </form>
</x-admin.layout>
