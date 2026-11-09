{{-- Loyalty cards | Requests: the card inventory, and cards customers asked for in the app through a friend. --}}
@php $pendingRequests = \App\Models\LoyaltyCardRequest::query()->pending()->count(); @endphp
<div class="scroll-row mb-6 flex gap-x-7 border-b border-outline-variant max-sm:border-b-0 max-sm:shadow-[inset_0_-1px_0_var(--color-outline-variant)]" role="tablist">
    @foreach ([['loyalty-cards.index', 'Cards', 'loyalty-cards.index'], ['loyalty-cards.requests.index', 'Requests', 'loyalty-cards.requests.*']] as [$route, $label, $pattern])
        @php $active = request()->routeIs($pattern); @endphp
        <a href="{{ route($route) }}" role="tab" @if ($active) aria-selected="true" @endif
           @class(['-mb-px inline-flex shrink-0 max-sm:mb-0 items-center gap-2 whitespace-nowrap border-b-2 pb-3 pt-1 text-[0.9375rem] font-medium transition-colors', 'border-gold text-on-surface' => $active, 'border-transparent text-on-surface-variant hover:text-on-surface' => ! $active])>
            {{ $label }}
            @if ($route === 'loyalty-cards.requests.index' && $pendingRequests > 0)
                <span class="rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-on-surface" title="Requests to verify">{{ $pendingRequests > 99 ? '99+' : $pendingRequests }}</span>
            @endif
        </a>
    @endforeach
</div>
