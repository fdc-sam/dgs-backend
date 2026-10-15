<x-admin.layout title="Promotions">
    <x-admin.page-header title="Promotions" subtitle="Offers shown in the app. Drafts stay hidden until published.">
        <x-slot:actions><x-admin.button :href="route('promotions.create')" icon="add">New promotion</x-admin.button></x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 flex flex-wrap gap-2" role="tablist">
        @foreach (['' => 'All', 'live' => 'Live now', 'draft' => 'Drafts', 'expired' => 'Expired'] as $key => $label)
            <a href="{{ route('promotions.index', array_filter(['state' => $key])) }}" role="tab" @if (request('state', '') === $key) aria-selected="true" @endif
               @class(['min-h-10 rounded-full px-4 py-2 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => request('state', '') === $key, 'border border-outline-variant hover:bg-surface-container' => request('state', '') !== $key])>{{ $label }}</a>
        @endforeach
    </div>

    <x-admin.card :padding="false">
        @if ($promotions->isEmpty())
            <x-admin.empty-state icon="sell" title="No promotions" message="Create an offer to show it in the app.">
                <x-admin.button :href="route('promotions.create')" icon="add">New promotion</x-admin.button>
            </x-admin.empty-state>
        @else
            <x-admin.table :headers="['Promotion', 'Type', 'Valid', 'Services', 'Status', '>Actions']">
                @foreach ($promotions as $promo)
                    @php $live = $promo->isCurrentlyActive(); $expired = $promo->end_at->isPast(); @endphp
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                @if ($promo->image)<img src="{{ \App\Support\Fmt::url($promo->image) }}" alt="" class="h-10 w-16 rounded-lg object-cover">@else<span class="flex h-10 w-16 items-center justify-center rounded-lg bg-gold-soft text-primary"><span class="material-symbols-outlined" aria-hidden="true">sell</span></span>@endif
                                <p class="font-medium">{{ $promo->name }}</p>
                            </div>
                        </td>
                        <td>{{ $promo->discount_type->label() }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $promo->start_at->format('M j') }} – {{ $promo->end_at->format('M j, Y') }}</td>
                        <td>{{ $promo->services_count ?: 'All' }}</td>
                        <td>
                            @if ($live)<x-admin.badge tone="success" icon="circle">Live</x-admin.badge>
                            @elseif ($expired)<x-admin.badge>Expired</x-admin.badge>
                            @elseif ($promo->status->value === 'draft')<x-admin.badge tone="warning">Draft</x-admin.badge>
                            @elseif ($promo->status->value === 'active')<x-admin.badge tone="info">Scheduled</x-admin.badge>
                            @else<x-admin.badge>Inactive</x-admin.badge>@endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                <x-admin.button :href="route('promotions.preview', $promo)" variant="text" size="sm">Preview</x-admin.button>
                                <x-admin.button :href="route('promotions.edit', $promo)" variant="text" size="sm">Edit</x-admin.button>
                                <form method="POST" action="{{ route('promotions.destroy', $promo) }}" data-confirm="Delete {{ $promo->name }}?">
                                    @csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$promotions" />
        @endif
    </x-admin.card>
</x-admin.layout>
