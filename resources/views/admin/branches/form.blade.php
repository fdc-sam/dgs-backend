@php
    $editing = $branch->exists;
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
@endphp
<x-admin.layout :title="$editing ? 'Edit branch' : 'New branch'">
    <x-admin.page-header :title="$editing ? $branch->name : 'New branch'" :back="route('branches.index')">
        @if ($editing && auth()->user()->can('change-setup'))
            <x-slot:actions>
                <form method="POST" action="{{ route('branches.destroy', $branch) }}" data-confirm="Delete {{ $branch->name }}?">
                    @csrf @method('DELETE')
                    <x-admin.button type="submit" variant="danger-text" icon="delete">Delete</x-admin.button>
                </form>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <form method="POST" action="{{ $editing ? route('branches.update', $branch) : route('branches.store') }}">
        @csrf @if ($editing) @method('PUT') @endif
        {{-- Test accounts see the shared setup read-only. --}}
        <fieldset class="min-w-0" @cannot('change-setup') disabled @endcannot>
        <div class="grid gap-6 lg:grid-cols-3">
            <x-admin.card title="Details" class="lg:col-span-2">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.input name="name" label="Branch name" :value="$branch->name" required class="sm:col-span-2" />
                    <x-admin.input name="address" label="Address" :value="$branch->address" class="sm:col-span-2" />
                    <x-admin.input name="phone" label="Phone" :value="$branch->phone" />
                    <x-admin.input name="email" type="email" label="Email" :value="$branch->email" />
                    <x-admin.input name="latitude" type="number" step="0.0000001" label="Latitude" :value="$branch->latitude" hint="Used for directions in the app." />
                    <x-admin.input name="longitude" type="number" step="0.0000001" label="Longitude" :value="$branch->longitude" />
                    <x-admin.textarea name="description" label="Description" :value="$branch->description" rows="3" class="sm:col-span-2" />
                    <x-admin.input name="slug" label="URL slug" :value="$branch->slug" hint="Leave blank to generate." />
                    <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="$branch->status?->value ?? 'active'" required hint="Inactive branches accept no bookings." />
                </div>
            </x-admin.card>

            <x-admin.card title="Business hours">
                <div class="space-y-2">
                    @foreach ($days as $i => $day)
                        @php $h = $hours[$i]; @endphp
                        <div x-data="{ open: {{ old("hours.$i.is_open", $h['is_open']) ? 'true' : 'false' }} }" class="rounded-xl bg-surface-container px-3 py-2">
                            <label class="flex min-h-9 items-center gap-2 text-sm font-medium">
                                <input type="hidden" name="hours[{{ $i }}][is_open]" value="0">
                                <input type="checkbox" name="hours[{{ $i }}][is_open]" value="1" x-model="open" class="h-5 w-5 accent-[var(--color-primary)]"> {{ $day }}
                                <span x-show="!open" class="ml-auto text-xs text-outline">Closed</span>
                            </label>
                            <div x-show="open" class="mt-1 flex items-center gap-2">
                                <input type="time" name="hours[{{ $i }}][opening_time]" value="{{ old("hours.$i.opening_time", $h['opening_time']) }}" class="field min-w-0 py-2 max-sm:px-3" aria-label="{{ $day }} opening">
                                <span class="text-xs text-on-surface-variant">to</span>
                                <input type="time" name="hours[{{ $i }}][closing_time]" value="{{ old("hours.$i.closing_time", $h['closing_time']) }}" class="field min-w-0 py-2 max-sm:px-3" aria-label="{{ $day }} closing">
                            </div>
                            @error("hours.$i.closing_time")<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </x-admin.card>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('branches.index')" variant="text">@can('change-setup') Cancel @else Back @endcan</x-admin.button>
            @can('change-setup')<x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Create branch' }}</x-admin.button> @endcan
        </div>
        </fieldset>
    </form>

    @if ($editing)
        <x-admin.card title="Closures & holidays" subtitle="The branch accepts no bookings on these dates." class="mt-6">
            @can('change-setup')
            <form method="POST" action="{{ route('branches.closures.store', $branch) }}" class="mb-4 flex flex-wrap items-end gap-3">
                @csrf
                <x-admin.input name="date" type="date" label="Date" :min="now()->toDateString()" required class="w-full sm:w-44" />
                <x-admin.input name="reason" label="Reason" placeholder="e.g. Holiday" class="w-full sm:w-64" />
                <x-admin.button type="submit" variant="tonal" icon="add" class="max-sm:w-full">Add closure</x-admin.button>
            </form>
            @endcan
            @forelse ($branch->closures as $closure)
                <div class="flex items-center justify-between border-t border-outline-variant/50 py-2 text-sm">
                    <span><strong>{{ $closure->date->format('D, M j, Y') }}</strong> @if ($closure->reason) · {{ $closure->reason }} @endif</span>
                    @can('change-setup')<form method="POST" action="{{ route('branches.closures.destroy', [$branch, $closure]) }}">@csrf @method('DELETE')
                        <x-admin.button type="submit" variant="danger-text" size="sm">Remove</x-admin.button>
                    </form> @endcan
                </div>
            @empty
                <p class="text-sm text-on-surface-variant">No upcoming closures.</p>
            @endforelse
        </x-admin.card>
    @endif
</x-admin.layout>
