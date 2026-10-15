{{-- Shared date-range selector. Expects $range and $action; optional $branches. --}}
<form method="GET" action="{{ $action }}" x-data="{ range: @js($range->preset) }" class="mb-6 flex flex-wrap items-end gap-3 rounded-card border border-outline-variant/70 bg-white p-4 shadow-e1">
    <div>
        <span class="label">Period</span>
        <div class="inline-flex flex-wrap rounded-full border border-outline-variant p-1" role="radiogroup" aria-label="Period">
            @foreach (\App\Support\Reports\DateRange::PRESETS as $key => $label)
                <label class="cursor-pointer">
                    <input type="radio" name="range" value="{{ $key }}" x-model="range" class="peer sr-only" @if ($key !== 'custom') @change="$el.form.submit()" @endif>
                    <span class="inline-flex min-h-9 items-center rounded-full px-4 text-sm font-medium peer-checked:bg-primary peer-checked:text-on-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>
    <template x-if="range === 'custom'">
        <div class="flex items-end gap-3">
            <div><label for="r_from" class="label">From</label><input id="r_from" type="date" name="from" value="{{ $range->from->toDateString() }}" class="field"></div>
            <div><label for="r_to" class="label">To</label><input id="r_to" type="date" name="to" value="{{ $range->to->toDateString() }}" class="field"></div>
        </div>
    </template>
    @if (! empty($branches) && $branches->isNotEmpty())
        <x-admin.select name="branch_id" label="Branch" :options="$branches" :value="request('branch_id')" placeholder="All branches" class="w-52" />
    @endif
    <x-admin.button type="submit" variant="tonal" icon="refresh">Apply</x-admin.button>
    <p class="ml-auto self-center text-sm text-on-surface-variant">{{ $range->label() }}</p>
</form>
