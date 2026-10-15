<x-admin.layout title="Reviews">
    <x-admin.page-header title="Reviews" subtitle="Approved reviews appear publicly in the app." />

    <div class="mb-4 flex flex-wrap gap-2" role="tablist">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
            <a href="{{ route('reviews.index', ['status' => $key]) }}" role="tab" @if ($status === $key) aria-selected="true" @endif
               @class(['inline-flex min-h-10 items-center gap-2 rounded-full px-4 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => $status === $key, 'border border-outline-variant hover:bg-surface-container' => $status !== $key])>
                {{ $label }} @if ($key !== 'all')<span class="text-xs opacity-70">{{ $counts[$key] ?? 0 }}</span>@endif
            </a>
        @endforeach
    </div>

    <x-admin.card :padding="false">
        @if ($reviews->isEmpty())
            <x-admin.empty-state icon="reviews" title="No reviews here" message="Reviews appear after customers rate completed appointments." />
        @else
            <x-admin.table :headers="['Customer', 'Service', 'Rating', 'Review', 'Date', 'Status', '>Actions']">
                @foreach ($reviews as $r)
                    <tr>
                        <td class="whitespace-nowrap font-medium">{{ $r->customer?->name }}</td>
                        <td>{{ $r->service?->name ?? '—' }}<p class="text-xs text-on-surface-variant">{{ $r->appointment?->staff?->user?->name }}</p></td>
                        <td class="whitespace-nowrap text-gold" aria-label="{{ $r->rating }} out of 5">{{ str_repeat('★', $r->rating) }}<span class="text-outline-variant">{{ str_repeat('★', 5 - $r->rating) }}</span></td>
                        <td class="max-w-md">
                            <p class="text-sm">{{ $r->review ?: '—' }}</p>
                            @if ($r->images->isNotEmpty())
                                <div class="mt-2 flex gap-2">@foreach ($r->images as $img)<a href="{{ $img->url() }}" target="_blank" rel="noopener"><img src="{{ $img->url() }}" alt="Review photo" class="h-12 w-12 rounded-lg object-cover"></a>@endforeach</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $r->created_at->format('M j, Y') }}</td>
                        <td><x-admin.badge :tone="['approved' => 'success', 'rejected' => 'danger'][$r->status->value] ?? 'warning'">{{ $r->status->label() }}</x-admin.badge></td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                @if ($r->status->value !== 'approved')
                                    <form method="POST" action="{{ route('reviews.approve', $r) }}">@csrf<x-admin.button type="submit" variant="tonal" size="sm" icon="check">Approve</x-admin.button></form>
                                @endif
                                @if ($r->status->value !== 'rejected')
                                    <form method="POST" action="{{ route('reviews.reject', $r) }}">@csrf<x-admin.button type="submit" variant="text" size="sm">Reject</x-admin.button></form>
                                @endif
                                <form method="POST" action="{{ route('reviews.destroy', $r) }}" data-confirm="Delete this review permanently from the app?">@csrf @method('DELETE')
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Delete</x-admin.button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$reviews" />
        @endif
    </x-admin.card>
</x-admin.layout>
