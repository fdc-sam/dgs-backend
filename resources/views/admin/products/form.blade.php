@php
    use App\Models\Product;
    use App\Support\Fmt;
    $editing = $product->exists;
    // Keep a unit saved before the dropdown existed selectable.
    $units = Product::UNITS + ($product->unit && ! isset(Product::UNITS[$product->unit]) ? [$product->unit => $product->unit] : []);
@endphp
<x-admin.layout :title="$editing ? 'Edit product' : 'New product'">
    <x-admin.page-header :title="$editing ? 'Edit '.$product->name : 'New product'" :back="route('products.index')"
                         subtitle="After saving, open a service and add this product under “Products used” to count its cost." />

    <form method="POST" action="{{ $editing ? route('products.update', $product) : route('products.store') }}" class="max-w-3xl">
        @csrf @if ($editing) @method('PUT') @endif
        {{-- Test accounts see the shared setup read-only. --}}
        <fieldset class="min-w-0" @cannot('change-setup') disabled @endcannot>
        <x-admin.card>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.input name="name" label="Product name" :value="$product->name" required class="sm:col-span-2" placeholder="e.g. Hair color" />
                <x-admin.input name="brand" label="Brand" :value="$product->brand" />
                <x-admin.select name="unit" label="Unit" :options="$units" :value="$product->unit" placeholder="Select a unit" required
                                hint="What one unit is. Services count usage in this unit." />
                <x-admin.input name="unit_cost" type="number" step="0.01" min="0" label="Cost per unit" prefix="₱" :value="$product->unit_cost" required
                               hint="What the salon pays for one unit." />
                @unless ($editing)
                    <x-admin.input name="stock_quantity" type="number" step="0.01" min="0" label="Current stock" :value="old('stock_quantity')"
                                   hint="How many units are on the shelf now, in the unit above. Completed services take stock off automatically." />
                @endunless
                <x-admin.input name="reorder_level" type="number" step="0.01" min="0" label="Low stock alert at" :value="$product->reorder_level"
                               hint="Flag the product as low when stock falls to this amount. Leave blank to warn only when it runs out." />
                <x-admin.textarea name="notes" label="Notes" :value="$product->notes" rows="3" class="sm:col-span-2" />
            </div>
        </x-admin.card>

        @if ($editing)
            <x-admin.card title="Stock" id="stock" class="mt-6">
                @php $status = $product->stockStatus(); @endphp
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-on-surface-variant">In stock</p>
                        <p class="text-2xl font-semibold @if ($status === 'out') text-danger @endif">{{ Fmt::quantity($product->stock_quantity) }} {{ $product->unit }}</p>
                        @if ($status === 'out')
                            <x-admin.badge tone="danger" class="mt-1">Out of stock</x-admin.badge>
                        @elseif ($status === 'low')
                            <x-admin.badge tone="warning" class="mt-1">Low · alert at {{ Fmt::quantity($product->reorder_level) }} {{ $product->unit }}</x-admin.badge>
                        @endif
                    </div>
                    @can('change-setup')<div class="flex flex-wrap gap-2 max-sm:w-full max-sm:[&>*]:flex-1">
                        <x-admin.button variant="tonal" icon="add" @click="$dispatch('open-modal', 'restock')">Add stock</x-admin.button>
                        <x-admin.button variant="outlined" icon="inventory" @click="$dispatch('open-modal', 'count')">Count stock</x-admin.button>
                    </div> @endcan
                </div>
                <p class="mt-3 text-xs text-on-surface-variant">Each completed appointment or walk-in takes off what its service uses (set under Services → Products used).</p>
            </x-admin.card>

            <x-admin.card title="Stock history" class="mt-6" :padding="false">
                @if ($movements->isEmpty())
                    <x-admin.empty-state icon="history" title="No stock changes yet" message="Add stock or count it to start tracking." class="py-6" />
                @else
                    <x-admin.table :headers="['Date', 'Change', '>Quantity', '>Balance', 'Details']">
                        @foreach ($movements as $m)
                            <tr>
                                <td class="whitespace-nowrap">{{ $m->created_at->format('M j, Y g:i A') }}</td>
                                <td class="whitespace-nowrap">{{ $m->label() }}</td>
                                <td class="whitespace-nowrap text-right font-medium {{ (float) $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ (float) $m->quantity > 0 ? '+' : '−' }}{{ Fmt::quantity(abs((float) $m->quantity)) }} {{ $product->unit }}</td>
                                <td class="whitespace-nowrap text-right">{{ Fmt::quantity($m->balance_after) }}</td>
                                <td class="text-sm">
                                    @if ($m->source instanceof \App\Models\AppointmentItem && $m->source->appointment)
                                        <a href="{{ route('appointments.show', $m->source->appointment_id) }}" class="text-primary hover:underline">{{ $m->source->appointment->appointment_number }}</a> · {{ $m->source->service_name }}
                                    @elseif ($m->source instanceof \App\Models\WalkInVisitItem)
                                        <a href="{{ route('walk-ins.show', $m->source->walk_in_visit_id) }}" class="text-primary hover:underline">Walk-in</a> · {{ $m->source->service_name }}
                                    @elseif ($m->source_type)
                                        <span class="text-on-surface-variant">Removed visit</span>
                                    @endif
                                    @if ($m->note)<span class="text-on-surface-variant">{{ $m->source_type ? ' · ' : '' }}“{{ $m->note }}”</span>@endif
                                    @if ($m->user)<p class="text-xs text-on-surface-variant">by {{ $m->user->name }}</p>@endif
                                </td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                    <x-admin.pagination :paginator="$movements" />
                @endif
            </x-admin.card>

            <x-admin.card title="Used in" subtitle="Change how much a service uses from that service's page." class="mt-6" :padding="false">
                @if ($product->services->isEmpty())
                    <x-admin.empty-state icon="content_cut" title="No service uses this product yet" class="py-6" />
                @else
                    <x-admin.table :headers="['Service', '>Per service', '>Cost per service']">
                        @foreach ($product->services as $service)
                            <tr>
                                <td><a href="{{ route('services.edit', $service) }}#products" class="font-medium hover:underline">{{ $service->name }}</a></td>
                                <td class="text-right">{{ Fmt::quantity($service->pivot->quantity) }} {{ $product->unit }}</td>
                                <td class="text-right">{{ Fmt::peso((float) $service->pivot->quantity * (float) $product->unit_cost) }}</td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                @endif
            </x-admin.card>
        @endif

        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('products.index')" variant="text">@can('change-setup') Cancel @else Back @endcan</x-admin.button>
            @can('change-setup')<x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Add product' }}</x-admin.button> @endcan
        </div>
        </fieldset>
    </form>

    @if ($editing)
        {{-- Outside the edit form: these dialogs submit their own forms. --}}
        <div x-data="{ target: @js(['name' => $product->name, 'unit' => $product->unit, 'stock' => Fmt::quantity($product->stock_quantity), 'restock' => route('products.restock', $product), 'count' => route('products.count', $product)]) }">
            @include('admin.products.partials.stock-modals')
        </div>
    @endif
</x-admin.layout>
