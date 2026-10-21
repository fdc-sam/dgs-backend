@props(['name' => 'account_type', 'test' => false])
{{-- Live / Test account choice. A test account sees, and is seen by, test accounts only. --}}
@php $current = old($name, $test ? 'test' : 'live'); @endphp
<fieldset {{ $attributes->class(['space-y-2']) }}>
    <legend class="label">Account type</legend>
    @foreach (['live' => ['Live account', 'Real customers, bookings and reports.', 'verified_user'],
               'test' => ['Test account', 'Sees only test customers and bookings, and live accounts never see its data.', 'science']] as $value => [$label, $hint, $icon])
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-outline-variant px-3.5 py-3 transition hover:bg-surface-container has-[:checked]:border-primary has-[:checked]:bg-surface-container">
            <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($current === $value) class="mt-0.5 h-5 w-5 shrink-0 accent-[var(--color-primary)]">
            <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5 text-sm font-semibold">
                    {{ $label }}
                    @if ($value === 'test')<x-admin.badge tone="warning">Test</x-admin.badge>@endif
                </span>
                <span class="mt-0.5 block text-xs text-on-surface-variant">{{ $hint }}</span>
            </span>
        </label>
    @endforeach
</fieldset>
