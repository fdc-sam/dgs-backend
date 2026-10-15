@php
    $visit = $walkInVisit;
    $completed = $visit->status === 'completed';
    $assignedStylists = $visit->items->pluck('staff.user.name')->filter()->unique()->join(', ');
@endphp
<x-admin.layout :title="$visit->guest_name ?: 'Walk-in guest'">
    <x-admin.page-header :title="$visit->guest_name ?: 'Walk-in guest'" :back="route('walk-ins.index')" :subtitle="'Walk-in visit · '.$visit->visited_at->format('M j, Y g:i A')">
        <x-slot:actions>
            <x-admin.badge :tone="$completed ? 'success' : 'info'">{{ $completed ? 'Completed' : 'In progress' }}</x-admin.badge>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="Walk-in details">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-on-surface-variant">Guest</dt><dd class="font-medium">{{ $visit->guest_name ?: 'Walk-in guest' }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Phone</dt><dd class="font-medium">{{ $visit->phone ?: 'No phone provided' }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Branch</dt><dd class="font-medium">{{ $visit->branch?->name ?? '—' }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Stylists</dt><dd class="font-medium">{{ $assignedStylists ?: ($visit->staff?->user?->name ?? 'Unassigned') }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Visit started</dt><dd class="font-medium">{{ $visit->visited_at->format('l, F j, Y · g:i A') }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Completed</dt><dd class="font-medium">{{ $visit->completed_at?->format('M j, Y · g:i A') ?? 'Not completed yet' }}</dd></div>
                    @if ($visit->notes)
                        <div class="sm:col-span-2"><dt class="text-sm text-on-surface-variant">Internal notes</dt><dd class="mt-1 rounded-xl bg-surface-container px-4 py-3 text-sm">{{ $visit->notes }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Services provided" :padding="false">
                @if ($errors->any())
                    <div class="mx-5 mt-4 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
                @endif
                <x-admin.table :headers="['Service', 'Stylist', 'Quantity', 'Price', 'Actions']">
                    @foreach ($visit->items as $item)
                        <tr>
                            <td class="font-medium">{{ $item->service_name }}</td>
                            <td>{{ $item->staff?->user?->name ?? 'Any qualified stylist' }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td class="text-right">₱{{ number_format((float) $item->subtotal, 2) }}</td>
                            <td class="text-right">
                                <button type="button" class="font-medium text-primary" @click="$dispatch('open-modal', 'walk-in-service-{{ $item->id }}')">Edit</button>
                                @if ($visit->items->count() > 1)
                                    <form method="POST" action="{{ route('walk-ins.items.destroy', [$visit, $item]) }}" class="ml-3 inline" data-confirm="Remove {{ $item->service_name }} from this walk-in? The visit total will be recalculated.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-medium text-danger">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr><td colspan="4" class="text-right font-semibold">Total</td><td class="text-right text-lg font-semibold">₱{{ number_format((float) $visit->total_amount, 2) }}</td></tr>
                </x-admin.table>
            </x-admin.card>

            <x-admin.card title="History" :padding="false">
                @forelse ($history as $entry)
                    <div class="flex gap-3 border-b border-outline-variant/50 px-5 py-3 text-sm last:border-0">
                        <span class="material-symbols-outlined text-base text-on-surface-variant" aria-hidden="true">{{ str_contains($entry->action, 'completed') ? 'task_alt' : 'history' }}</span>
                        <div><p class="font-medium">{{ str($entry->action)->replace('_', ' ')->title() }}</p><p class="text-xs text-on-surface-variant">{{ $entry->user?->name ?? 'System' }} · {{ $entry->created_at->format('M j, Y g:i A') }}</p></div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-on-surface-variant">No changes recorded.</p>
                @endforelse
            </x-admin.card>
        </div>

        <div class="space-y-6">
            @unless ($completed)
                <x-admin.card title="Service progress" subtitle="Mark this visit complete after all selected services have been provided.">
                    <form method="POST" action="{{ route('walk-ins.complete', $visit) }}" data-confirm="Mark all services for this walk-in as complete?">
                        @csrf
                        <x-admin.button type="submit" icon="task_alt" class="w-full">Mark services complete</x-admin.button>
                    </form>
                </x-admin.card>
            @else
                <x-admin.card title="Service progress">
                    <p class="flex items-center gap-2 text-sm font-medium text-success"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>All services completed</p>
                    <p class="mt-2 text-sm text-on-surface-variant">This completed visit is included in the dashboard’s completion and revenue totals.</p>
                </x-admin.card>
            @endunless

            <x-admin.card title="Visit summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Services</dt><dd class="font-medium">{{ $visit->items->count() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Branch</dt><dd class="text-right font-medium">{{ $visit->branch?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-t border-outline-variant pt-3"><dt class="font-semibold">Total</dt><dd class="font-semibold">₱{{ number_format($visit->total_amount, 2) }}</dd></div>
                </dl>
            </x-admin.card>
        </div>
    </div>
    @foreach ($visit->items as $item)
        <x-admin.modal :name="'walk-in-service-'.$item->id" title="Edit service">
            <form method="POST" action="{{ route('walk-ins.items.update', [$visit, $item]) }}" class="walkin-item-editor space-y-4"
                  data-current-staff="{{ $item->staff_profile_id }}">
                @csrf
                @method('PUT')
                <div>
                    <label for="service-{{ $item->id }}" class="label">Service</label>
                    <select id="service-{{ $item->id }}" name="service_id" class="field walkin-item-service" required>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}" @selected($service->id === $item->service_id)>{{ $service->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="stylist-{{ $item->id }}" class="label">Stylist</label>
                    <select id="stylist-{{ $item->id }}" name="staff_profile_id" class="field walkin-item-staff">
                        <option value="">Any qualified stylist</option>
                    </select>
                </div>
                <p class="text-sm text-on-surface-variant">The service price updates to the current branch price when you save.</p>
                <div class="flex justify-end gap-2">
                    <x-admin.button type="button" variant="text" @click="$dispatch('close-modal', 'walk-in-service-{{ $item->id }}')">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save changes</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    @endforeach
    <script>
        document.querySelectorAll('.walkin-item-editor').forEach((form) => {
            const service = form.querySelector('.walkin-item-service');
            const stylist = form.querySelector('.walkin-item-staff');
            const staff = @js($staff->map(fn ($profile) => [
                'id' => $profile->id,
                'name' => $profile->user?->name.($profile->position ? ' · '.$profile->position : ''),
                'serviceIds' => $profile->services->pluck('id')->all(),
            ])->values());
            const currentStaff = form.dataset.currentStaff || '';
            const renderStylists = () => {
                const eligible = staff.filter((person) => person.serviceIds.includes(service.value));
                stylist.replaceChildren(new Option('Any qualified stylist', ''));
                eligible.forEach((person) => stylist.add(new Option(person.name, person.id)));
                stylist.value = eligible.some((person) => person.id === currentStaff) ? currentStaff : '';
            };
            service.addEventListener('change', renderStylists);
            renderStylists();
        });
    </script>
</x-admin.layout>
