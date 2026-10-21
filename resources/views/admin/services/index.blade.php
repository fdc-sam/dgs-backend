<x-admin.layout title="Services">
    <x-admin.page-header title="Services" subtitle="Prices, durations and where each service is offered.">
        @can('change-setup')<x-slot:actions><x-admin.button :href="route('services.create')" icon="add">New service</x-admin.button></x-slot:actions> @endcan
    </x-admin.page-header>

    <x-admin.filter-bar :action="route('services.index')">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Service name" class="w-64" />
        <x-admin.select name="category_id" label="Category" :options="$categories" :value="request('category_id')" placeholder="All categories" class="w-52" />
        <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="request('status')" placeholder="Any" class="w-40" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($services->isEmpty())
            <x-admin.empty-state icon="content_cut" title="No services found">
                @can('change-setup')<x-admin.button :href="route('services.create')" icon="add">New service</x-admin.button> @endcan
            </x-admin.empty-state>
        @else
            <x-admin.table :headers="['Service', 'Category', '>Price', 'Duration', 'Options', 'Branches', 'Staff', 'Status', '>Actions']">
                @foreach ($services as $service)
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                @if ($service->image)<img src="{{ \App\Support\Fmt::url($service->image) }}" alt="" class="h-10 w-10 rounded-xl object-cover">@else<span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gold-soft text-primary"><span class="material-symbols-outlined" aria-hidden="true">content_cut</span></span>@endif
                                <div>
                                    <p class="font-medium">{{ $service->name }} @if ($service->is_featured)<x-admin.badge tone="primary" icon="star" class="ml-1">Featured</x-admin.badge>@endif</p>
                                    <p class="max-w-xs truncate text-xs text-on-surface-variant">{{ $service->short_description }}</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ $service->category?->name }}</td>
                        <td class="text-right font-medium">₱{{ number_format($service->regular_price, 2) }}
                            @if ($service->ownDeposit())
                                <p class="text-xs font-normal text-on-surface-variant" title="GCash downpayment QR">₱{{ number_format($service->deposit_amount, 2) }} downpayment</p>
                            @endif
                            @if ($service->products->isNotEmpty())
                                <p class="text-xs font-normal text-on-surface-variant" title="{{ $service->products->map(fn ($p) => $p->name)->join(', ') }}">₱{{ number_format($service->productCost(), 2) }} products</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $service->duration_minutes }} min <span class="text-xs text-on-surface-variant">+{{ $service->buffer_minutes }}</span></td>
                        <td>{{ $service->variants_count ?: '—' }}</td>
                        <td>{{ $service->branches_count }}</td>
                        <td>{{ $service->staff_count }}</td>
                        <td><x-admin.badge :tone="$service->status->value === 'active' ? 'success' : 'neutral'">{{ $service->status->label() }}</x-admin.badge></td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <x-admin.button :href="route('services.edit', $service)" variant="text" size="sm">@can('change-setup') Edit @else View @endcan</x-admin.button>
                                @can('change-setup')<form method="POST" action="{{ route('services.destroy', $service) }}" data-confirm="Delete {{ $service->name }}? Existing appointments are kept.">
                                    @csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form> @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$services" />
        @endif
    </x-admin.card>
</x-admin.layout>
