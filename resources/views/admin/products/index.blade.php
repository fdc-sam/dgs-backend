@php use App\Support\Fmt; @endphp
<x-admin.layout title="Inventory">
    <x-admin.page-header title="Inventory" subtitle="Products used in services and what each unit costs. Their cost is taken off revenue to give net revenue.">
        @can('change-setup')<x-slot:actions><x-admin.button :href="route('products.create')" icon="add">New product</x-admin.button></x-slot:actions> @endcan
    </x-admin.page-header>

    @if ($uncounted > 0)
        <div class="mb-4 flex items-start gap-3 rounded-card border border-info/30 bg-info-container px-4 py-3 text-sm text-info" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">inventory</span>
            <p>{{ $uncounted }} {{ \Illuminate\Support\Str::plural('product', $uncounted) }} {{ $uncounted === 1 ? 'has' : 'have' }} no stock recorded yet. Use <span class="font-semibold">Count</span> to enter what is on the shelf; completed services take stock off from then on.</p>
        </div>
    @elseif ($lowCount > 0 && request('stock') !== 'low')
        <div class="mb-4 flex items-start gap-3 rounded-card border border-warning/40 bg-warning-container px-4 py-3 text-sm text-warning" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">warning</span>
            <p>{{ $lowCount }} {{ \Illuminate\Support\Str::plural('product', $lowCount) }} {{ $lowCount === 1 ? 'is' : 'are' }} low or out of stock. <a href="{{ route('products.index', ['stock' => 'low']) }}" class="font-semibold underline">Show them</a></p>
        </div>
    @endif

    <x-admin.filter-bar :action="route('products.index')">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Product or brand" class="w-64" />
        <x-admin.select name="stock" label="Stock" :options="['low' => 'Low or out of stock']" :value="request('stock')" placeholder="All products" class="w-52" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($products->isEmpty())
            <x-admin.empty-state icon="inventory_2" :title="request('search') ? 'No products found' : 'No products yet'"
                                 message="Add the products your services use, like hair color or treatment, with what one unit costs.">
                @can('change-setup')<x-admin.button :href="route('products.create')" icon="add">New product</x-admin.button> @endcan
            </x-admin.empty-state>
        @else
            <div x-data="{ target: { name: '', unit: '', stock: '', restock: '', count: '' } }">
            <x-admin.table :headers="['Product', '>In stock', '>Cost per unit', 'Used in', '>Actions']">
                @foreach ($products as $product)
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gold-soft text-primary"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></span>
                                <div class="min-w-0">
                                    <p class="font-medium">{{ $product->name }}</p>
                                    @if ($product->brand)<p class="max-w-xs truncate text-xs text-on-surface-variant">{{ $product->brand }}</p>@endif
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            @php $status = $product->stockStatus(); @endphp
                            <p class="font-medium @if ($status === 'out') text-danger @endif">{{ Fmt::quantity($product->stock_quantity) }} {{ $product->unit }}</p>
                            @if (! $product->stock_counted)
                                <x-admin.badge tone="info">Not counted</x-admin.badge>
                            @elseif ($status === 'out')
                                <x-admin.badge tone="danger">Out of stock</x-admin.badge>
                            @elseif ($status === 'low')
                                <x-admin.badge tone="warning">Low · alert at {{ Fmt::quantity($product->reorder_level) }}</x-admin.badge>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right font-medium">{{ Fmt::peso($product->unit_cost) }}</td>
                        <td>
                            @forelse ($product->services->take(3) as $service)
                                <p class="text-sm"><a href="{{ route('services.edit', $service) }}" class="hover:underline">{{ $service->name }}</a>
                                    <span class="text-xs text-on-surface-variant">· {{ Fmt::quantity($service->pivot->quantity) }} {{ $product->unit }}</span></p>
                            @empty
                                <span class="text-sm text-on-surface-variant">Not used by any service</span>
                            @endforelse
                            @if ($product->services->count() > 3)
                                <p class="text-xs text-on-surface-variant">+{{ $product->services->count() - 3 }} more</p>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                @php $target = ['name' => $product->name, 'unit' => $product->unit, 'stock' => Fmt::quantity($product->stock_quantity), 'restock' => route('products.restock', $product), 'count' => route('products.count', $product)]; @endphp
                                @can('change-setup')
                                <x-admin.button variant="tonal" size="sm" icon="add" @click="target = {{ \Illuminate\Support\Js::from($target) }}; $dispatch('open-modal', 'restock')">Add stock</x-admin.button>
                                <x-admin.button variant="text" size="sm" @click="target = {{ \Illuminate\Support\Js::from($target) }}; $dispatch('open-modal', 'count')">Count</x-admin.button>
                                @endcan
                                <x-admin.button :href="route('products.edit', $product)" variant="text" size="sm">@can('change-setup') Edit @else View @endcan</x-admin.button>
                                @can('change-setup')<form method="POST" action="{{ route('products.destroy', $product) }}"
                                      data-confirm="Delete {{ $product->name }}?{{ $product->services->isNotEmpty() ? ' It will be removed from '.$product->services->count().' '.\Illuminate\Support\Str::plural('service', $product->services->count()).'.' : '' }} Completed visits keep their recorded costs.">
                                    @csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form> @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            @include('admin.products.partials.stock-modals')
            </div>
            <x-admin.pagination :paginator="$products" />
        @endif
    </x-admin.card>
</x-admin.layout>
