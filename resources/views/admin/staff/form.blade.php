@php
    $editing = $staff->exists;
    $user = $staff->user;
    $assignedBranches = old('branches', $editing ? $staff->branches->pluck('id')->all() : []);
    $primary = old('primary_branch', $editing ? $staff->branches->firstWhere('pivot.is_primary', true)?->id : null);
    $assignedServices = old('services', $editing ? $staff->services->pluck('id')->all() : []);
    $schedule = $editing ? $staff->schedules->keyBy('day_of_week') : collect();
@endphp
<x-admin.layout :title="$editing ? 'Edit staff' : 'Add staff'">
    <x-admin.page-header :title="$editing ? $user->name : 'Add staff member'" :back="route('staff.index')" :subtitle="$editing ? $staff->position : 'Creates a sign-in for the admin portal (staff role).'">
        @if ($editing)
            <x-slot:actions>
                <form method="POST" action="{{ route('staff.destroy', $staff) }}" data-confirm="Remove {{ $user->name }}? Their sign-in will be disabled; past appointments are kept.">
                    @csrf @method('DELETE')
                    <x-admin.button type="submit" variant="danger-text" icon="person_remove">Remove</x-admin.button>
                </form>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <form method="POST" action="{{ $editing ? route('staff.update', $staff) : route('staff.store') }}" enctype="multipart/form-data">
        @csrf @if ($editing) @method('PUT') @endif
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-admin.card title="Account & profile">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.input name="name" label="Full name" :value="$user?->name" required />
                        <x-admin.input name="position" label="Position" :value="$staff->position" placeholder="e.g. Senior Stylist" />
                        <x-admin.input name="email" type="email" label="Email (sign-in)" :value="$user?->email" required />
                        <x-admin.input name="phone" label="Mobile number" :value="$user?->phone" placeholder="09XXXXXXXXX" />
                        <x-admin.input name="password" type="password" :label="$editing ? 'New password' : 'Password'" :required="! $editing" autocomplete="new-password" :hint="$editing ? 'Leave blank to keep the current password.' : 'At least 8 characters with letters and numbers.'" />
                        <x-admin.input name="employee_code" label="Employee code" :value="$staff->employee_code" />
                        <x-admin.input name="hire_date" type="date" label="Hire date" :value="$staff->hire_date?->toDateString()" />
                        <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="$staff->status?->value ?? 'active'" required hint="Inactive staff cannot be booked or sign in." />
                        <x-admin.textarea name="bio" label="Bio (shown in the app)" :value="$staff->bio" rows="3" class="sm:col-span-2" />
                    </div>
                </x-admin.card>

                <x-admin.card title="Weekly schedule" subtitle="Customers can only book times inside these hours (and the branch's opening hours).">
                    <div class="space-y-3" x-data>
                        @foreach ($days as $i => $day)
                            @php $row = $schedule->get($i); $working = (bool) old("schedule.$i.is_working", $row?->is_working ?? (! $editing && $i !== 0)); @endphp
                            <div class="rounded-2xl bg-surface-container p-3" x-data="{ on: {{ $working ? 'true' : 'false' }} }">
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="flex min-h-11 w-36 items-center gap-2 font-medium">
                                        <input type="hidden" name="schedule[{{ $i }}][is_working]" value="0">
                                        <input type="checkbox" name="schedule[{{ $i }}][is_working]" value="1" x-model="on" class="h-5 w-5 accent-[var(--color-primary)]"> {{ $day }}
                                    </label>
                                    <template x-if="on">
                                        <div class="flex flex-1 flex-wrap items-center gap-2">
                                            <select name="schedule[{{ $i }}][branch_id]" class="field w-44" aria-label="{{ $day }} branch">
                                                @foreach ($branches as $id => $name)<option value="{{ $id }}" @selected(old("schedule.$i.branch_id", $row?->branch_id) === $id)>{{ $name }}</option>@endforeach
                                            </select>
                                            <input type="time" name="schedule[{{ $i }}][start_time]" value="{{ old("schedule.$i.start_time", substr($row?->start_time ?? '09:00', 0, 5)) }}" class="field w-32" aria-label="{{ $day }} start">
                                            <span class="text-sm text-on-surface-variant">to</span>
                                            <input type="time" name="schedule[{{ $i }}][end_time]" value="{{ old("schedule.$i.end_time", substr($row?->end_time ?? '18:00', 0, 5)) }}" class="field w-32" aria-label="{{ $day }} end">
                                            <span class="ml-2 text-sm text-on-surface-variant">Break</span>
                                            <input type="time" name="schedule[{{ $i }}][break_start]" value="{{ old("schedule.$i.break_start", substr($row?->break_start ?? ($editing ? '' : '12:00'), 0, 5)) }}" class="field w-32" aria-label="{{ $day }} break start">
                                            <input type="time" name="schedule[{{ $i }}][break_end]" value="{{ old("schedule.$i.break_end", substr($row?->break_end ?? ($editing ? '' : '13:00'), 0, 5)) }}" class="field w-32" aria-label="{{ $day }} break end">
                                        </div>
                                    </template>
                                    <span x-show="!on" class="text-sm text-on-surface-variant">Day off</span>
                                </div>
                                @foreach (['branch_id', 'start_time', 'end_time', 'break_start', 'break_end'] as $f)
                                    @error("schedule.$i.$f")<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </x-admin.card>

                <x-admin.card title="Services performed">
                    <div class="grid gap-x-6 sm:grid-cols-2">
                        @foreach ($services as $category => $items)
                            <div class="mb-3">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-outline">{{ $category }}</p>
                                @foreach ($items as $service)
                                    <x-admin.checkbox name="services[]" :value="$service->id" :label="$service->name" :checked="in_array($service->id, $assignedServices, true)" />
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </x-admin.card>
            </div>

            <div class="space-y-6">
                <x-admin.card title="Photo"><x-admin.image-field name="photo" label="Profile photo" :current="$user?->profilePhotoUrl()" /></x-admin.card>
                <x-admin.card title="Branches">
                    @foreach ($branches as $id => $name)
                        <div class="flex items-center justify-between gap-2">
                            <x-admin.checkbox name="branches[]" :value="$id" :label="$name" :checked="in_array($id, $assignedBranches, true)" />
                            <label class="flex items-center gap-1 text-xs text-on-surface-variant"><input type="radio" name="primary_branch" value="{{ $id }}" @checked($primary === $id) class="accent-[var(--color-primary)]"> Primary</label>
                        </div>
                    @endforeach
                    @error('branches')<p class="mt-1 text-xs font-medium text-danger">{{ $message }}</p>@enderror
                </x-admin.card>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <x-admin.button :href="route('staff.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="save">{{ $editing ? 'Save changes' : 'Create staff member' }}</x-admin.button>
        </div>
    </form>

    @if ($editing)
        <x-admin.card title="Days off" subtitle="Leave, holidays or training. Blocks bookings on those dates." class="mt-6">
            <form method="POST" action="{{ route('staff.days-off.store', $staff) }}" class="mb-4 flex flex-wrap items-end gap-3">
                @csrf
                <x-admin.input name="date" type="date" label="Date" :min="now()->toDateString()" required class="w-44" />
                <x-admin.select name="branch_id" label="Branch" :options="$staff->branches->pluck('name', 'id')" placeholder="All branches" class="w-48" />
                <x-admin.input name="reason" label="Reason" placeholder="Optional" class="w-64" />
                <x-admin.button type="submit" variant="tonal" icon="add">Add day off</x-admin.button>
            </form>
            @forelse ($staff->daysOff as $off)
                <div class="flex items-center justify-between border-t border-outline-variant/50 py-2 text-sm">
                    <span><strong>{{ $off->date->format('D, M j, Y') }}</strong> · {{ $off->branch?->name ?? 'All branches' }} @if ($off->reason) · {{ $off->reason }} @endif</span>
                    <form method="POST" action="{{ route('staff.days-off.destroy', [$staff, $off]) }}">@csrf @method('DELETE')
                        <x-admin.button type="submit" variant="danger-text" size="sm">Remove</x-admin.button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-on-surface-variant">No days off scheduled.</p>
            @endforelse
        </x-admin.card>
    @endif
</x-admin.layout>
