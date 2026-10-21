@php $p = $payload; @endphp
<x-admin.layout title="Preview promotion">
    <x-admin.page-header :title="'Preview: '.$promotion->name" :back="route('promotions.index')" subtitle="This is how the promotion appears in the mobile app.">
        <x-slot:actions>
            <x-admin.button :href="route('promotions.edit', $promotion)" variant="outlined" icon="edit">@can('change-setup') Edit @else View @endcan</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="mx-auto w-full max-w-sm rounded-[2.5rem] border-8 border-on-surface bg-surface p-4 shadow-e2" aria-label="Phone preview">
            <div class="overflow-hidden rounded-3xl bg-white shadow-e1">
                @if ($p['image_url'])
                    <img src="{{ $p['image_url'] }}" alt="" class="aspect-[16/9] w-full object-cover">
                @else
                    <div class="flex aspect-[16/9] items-center justify-center bg-gradient-to-br from-gold to-primary text-white"><span class="material-symbols-outlined text-5xl" aria-hidden="true">sell</span></div>
                @endif
                <div class="p-4">
                    <span class="inline-block rounded-full bg-gold px-3 py-1 text-xs font-bold text-on-primary-container">{{ $p['discount_label'] }}</span>
                    <p class="mt-2 text-lg font-semibold">{{ $p['name'] }}</p>
                    <p class="mt-1 text-sm text-on-surface-variant">{{ $p['description'] }}</p>
                    <p class="mt-3 text-xs text-outline">Valid until {{ $promotion->end_at->format('M j, Y') }}</p>
                    @if ($promotion->services->isNotEmpty())
                        <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">Included services</p>
                        <ul class="mt-1 text-sm">@foreach ($promotion->services as $s)<li>• {{ $s->name }}</li>@endforeach</ul>
                    @endif
                    <div class="mt-4 w-full rounded-full bg-primary py-3 text-center text-sm font-medium text-on-primary">Book now</div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <x-admin.card title="Status">
                <p class="text-sm">Current status: <strong>{{ $promotion->status->label() }}</strong>.
                    {{ $promotion->isCurrentlyActive() ? 'It is live in the app right now.' : ($promotion->start_at->isFuture() ? 'It will appear on '.$promotion->start_at->format('M j, Y g:i A').' once published.' : ($promotion->end_at->isPast() ? 'It has expired.' : 'It is hidden until published.')) }}</p>
                @if ($promotion->status->value !== 'active' && auth()->user()->can('change-setup'))
                    <form method="POST" action="{{ route('promotions.publish', $promotion) }}" class="mt-4 space-y-3">
                        @csrf
                        <x-admin.checkbox name="notify" label="Send a push notification to all customers" hint="Only sent if the promotion is live now." />
                        <x-admin.button type="submit" icon="publish">Publish promotion</x-admin.button>
                    </form>
                @endif
            </x-admin.card>
            <x-admin.card title="Terms & conditions"><p class="whitespace-pre-line text-sm">{{ $promotion->terms_and_conditions ?: 'None.' }}</p></x-admin.card>
            <x-admin.card title="Branches"><p class="text-sm">{{ $promotion->branches->pluck('name')->join(', ') ?: 'All branches' }}</p></x-admin.card>
        </div>
    </div>
</x-admin.layout>
