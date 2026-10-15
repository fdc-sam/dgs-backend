@php
    use App\Enums\AppointmentStatus;
    use App\Enums\PaymentStatus;
    $a = $appointment;
    $receipts = $a->payments->sortByDesc('id')->values();
    $waiting = $a->payment_status === PaymentStatus::Submitted ? $receipts->firstWhere('status', \App\Models\Payment::SUBMITTED) : null;
    $user = auth()->user();
    $assignedStylists = $a->items->pluck('staff.user.name')->filter()->unique()->join(', ');
    $actionMeta = [
        'confirmed' => ['Confirm', 'check_circle', 'filled'],
        'in_progress' => ['Start service', 'play_circle', 'tonal'],
        'completed' => ['Mark completed', 'task_alt', 'tonal'],
        'no_show' => ['Mark no-show', 'person_off', 'outlined'],
    ];
@endphp
<x-admin.layout :title="$a->appointment_number">
    <x-admin.page-header :title="$a->appointment_number" :back="route('appointments.index')"
                         :subtitle="'Booked '.$a->created_at->format('M j, Y g:i A')">
        <x-slot:actions>
            <x-admin.status-badge :status="$a->status" />
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="Booking details">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-on-surface-variant">Date & time</dt><dd class="font-medium">{{ $a->startsAt()->format('l, F j, Y') }}<br>{{ $a->startsAt()->format('g:i A') }} – {{ $a->endsAt()->format('g:i A') }} <span class="text-on-surface-variant">({{ $a->durationMinutes() }} min + {{ $a->buffer_minutes }} min buffer)</span></dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Branch</dt><dd class="font-medium">{{ $a->branch->name }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Stylists</dt><dd class="font-medium">{{ $assignedStylists ?: ($a->staff?->user?->name ?? 'Unassigned') }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Promotion</dt><dd class="font-medium">{{ $a->promotion?->name ?? '—' }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Arrival</dt><dd class="font-medium">
                        @if ($a->checked_in_at)
                            Arrived {{ $a->checked_in_at->format('g:i A') }}@if ($a->checkedInBy) <span class="text-on-surface-variant">· checked in by {{ $a->checkedInBy->name }}</span>@endif
                        @else
                            <span class="text-on-surface-variant">Not arrived yet</span>
                        @endif
                    </dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Booked via</dt><dd class="font-medium">{{ $a->source === 'walk_in' ? 'Walk-in (at the counter)' : 'Customer app' }}</dd></div>
                    @if ($a->customer_notes)
                        <div class="sm:col-span-2"><dt class="text-sm text-on-surface-variant">Customer notes</dt><dd class="mt-1 rounded-xl bg-gold-soft px-4 py-3 text-sm">{{ $a->customer_notes }}</dd></div>
                    @endif
                    @if ($a->status === AppointmentStatus::Cancelled)
                        <div class="sm:col-span-2"><dt class="text-sm text-on-surface-variant">Cancelled</dt><dd class="text-sm">{{ $a->cancelled_at?->format('M j, Y g:i A') }} by {{ $a->cancelledBy?->name ?? 'unknown' }}@if ($a->cancellation_reason) — “{{ $a->cancellation_reason }}”@endif</dd></div>
                    @endif
                </dl>
            </x-admin.card>

            <x-admin.card title="Services" :padding="false">
                <x-admin.table :headers="['Service', 'Option', 'Stylist', 'Duration', '>Price']">
                    @foreach ($a->items as $item)
                        <tr>
                            <td class="font-medium">{{ $item->service_name }}
                                @if ((float) $item->deposit_amount > 0)<p class="text-xs font-normal text-on-surface-variant">₱{{ number_format($item->deposit_amount, 2) }} GCash downpayment</p>@endif
                            </td>
                            <td>{{ $item->variant_name ?? '—' }}</td>
                            <td>{{ $item->staff?->user?->name ?? 'Any qualified stylist' }}</td>
                            <td>{{ $item->duration_minutes }} min</td>
                            <td class="text-right">₱{{ number_format($item->subtotal, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr><td colspan="4" class="text-right text-on-surface-variant">Subtotal</td><td class="text-right">₱{{ number_format($a->subtotal, 2) }}</td></tr>
                    @if ((float) $a->discount_amount > 0)
                        <tr><td colspan="4" class="text-right text-on-surface-variant">Discount</td><td class="text-right text-success">−₱{{ number_format($a->discount_amount, 2) }}</td></tr>
                    @endif
                    <tr><td colspan="4" class="text-right font-semibold">Total</td><td class="text-right text-lg font-semibold">₱{{ number_format($a->total_amount, 2) }}</td></tr>
                    @if ((float) $a->amount_paid > 0)
                        <tr><td colspan="4" class="text-right text-on-surface-variant">Downpayment received (GCash)</td><td class="text-right text-success">−₱{{ number_format($a->amount_paid, 2) }}</td></tr>
                        <tr><td colspan="4" class="text-right font-semibold">Balance to pay at the salon</td><td class="text-right text-lg font-semibold">₱{{ number_format($a->balanceDue(), 2) }}</td></tr>
                    @elseif ($a->payment_status->isHolding() || $a->payment_status === PaymentStatus::Submitted)
                        <tr><td colspan="4" class="text-right text-on-surface-variant">Downpayment due (GCash)</td><td class="text-right">₱{{ number_format($a->deposit_amount, 2) }}</td></tr>
                    @endif
                </x-admin.table>
            </x-admin.card>

            @if ($a->review)
                <x-admin.card title="Customer review">
                    <p class="text-gold" aria-label="{{ $a->review->rating }} out of 5 stars">{{ str_repeat('★', $a->review->rating) }}<span class="text-outline-variant">{{ str_repeat('★', 5 - $a->review->rating) }}</span></p>
                    <p class="mt-2 text-sm">{{ $a->review->review ?: 'No written review.' }}</p>
                    <x-admin.badge :tone="['approved' => 'success', 'rejected' => 'danger'][$a->review->status->value] ?? 'warning'" class="mt-3">{{ $a->review->status->label() }}</x-admin.badge>
                </x-admin.card>
            @endif

            <x-admin.card title="History" :padding="false">
                @forelse ($history as $entry)
                    <div class="flex gap-3 border-b border-outline-variant/50 px-5 py-3 text-sm last:border-0">
                        <span class="material-symbols-outlined text-outline" aria-hidden="true">history</span>
                        <div>
                            <p><span class="font-medium">{{ \Illuminate\Support\Str::of($entry->action)->after('appointment.')->replace('_', ' ')->ucfirst() }}</span> by {{ $entry->user?->name ?? 'System' }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $entry->created_at->format('M j, Y g:i A') }}
                                @if (isset($entry->new_values['starts_at'])) · new time {{ \Carbon\Carbon::parse($entry->new_values['starts_at'])->format('M j, g:i A') }} @endif
                                @if (isset($entry->new_values['reference_number'])) · Ref No. {{ $entry->new_values['reference_number'] }} @endif
                                @if (isset($entry->new_values['amount'])) · ₱{{ number_format($entry->new_values['amount'], 2) }} @endif
                                @if (isset($entry->new_values['reason']) && str_starts_with($entry->action, 'appointment.payment')) · “{{ $entry->new_values['reason'] }}” @endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-on-surface-variant">No changes recorded.</p>
                @endforelse
            </x-admin.card>
        </div>

        <div class="space-y-6">
            @if ($a->payment_status !== PaymentStatus::NotRequired)
                @include('admin.appointments.partials.payment-card')
            @endif

            @if (! $a->status->isFinal())
                <x-admin.card title="Actions">
                    <div class="flex flex-col gap-2">
                        @can('updateStatus', $a)
                            @foreach ($transitions as $to)
                                @php [$label, $icon, $variant] = $actionMeta[$to->value]; @endphp
                                <form method="POST" action="{{ route('appointments.status', $a) }}" @if ($to === AppointmentStatus::NoShow) data-confirm="Mark this appointment as a no-show?" @endif>
                                    @csrf <input type="hidden" name="status" value="{{ $to->value }}">
                                    <x-admin.button type="submit" :variant="$variant" :icon="$icon" class="w-full">{{ $label }}</x-admin.button>
                                </form>
                            @endforeach
                        @endcan
                        @can('reschedule', $a)
                            <x-admin.button variant="outlined" icon="event_repeat" class="w-full" @click="$dispatch('open-modal', 'reschedule')">Reschedule</x-admin.button>
                        @endcan
                        @can('cancel', $a)
                            <x-admin.button variant="danger-text" icon="cancel" class="w-full" @click="$dispatch('open-modal', 'cancel')">Cancel appointment</x-admin.button>
                        @endcan
                    </div>
                </x-admin.card>
            @endif

            <x-admin.card title="Customer">
                <p class="font-semibold">{{ $a->customer->name }}</p>
                <p class="text-sm text-on-surface-variant"><a class="hover:underline" href="mailto:{{ $a->customer->email }}">{{ $a->customer->email }}</a></p>
                <p class="text-sm text-on-surface-variant"><a class="hover:underline" href="tel:{{ $a->customer->phone }}">{{ $a->customer->phone ?? 'No phone' }}</a></p>
                @can('manage')
                    <x-admin.button :href="route('customers.show', $a->customer)" variant="text" size="sm" class="-ml-3 mt-2">View profile & history</x-admin.button>
                @endcan
            </x-admin.card>

            @can('updateNotes', $a)
                <x-admin.card title="Internal notes" subtitle="Only visible to the salon team.">
                    <form method="POST" action="{{ route('appointments.notes', $a) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <x-admin.textarea name="admin_notes" :value="$a->admin_notes" rows="4" aria-label="Internal notes" />
                        <x-admin.button type="submit" variant="tonal" size="sm">Save notes</x-admin.button>
                    </form>
                </x-admin.card>
            @endcan
        </div>
    </div>

    @if ($a->payment_status !== PaymentStatus::NotRequired)
        @include('admin.appointments.partials.payment-modals')
    @endif

    <x-admin.modal name="cancel" title="Cancel appointment">
        <form method="POST" action="{{ route('appointments.cancel', $a) }}" class="space-y-4">
            @csrf
            <p class="text-sm text-on-surface-variant">The customer will be notified. This frees the time slot for other bookings.</p>
            <x-admin.textarea name="reason" label="Reason (shared with the customer)" rows="3" />
            <div class="flex justify-end gap-2">
                <x-admin.button variant="text" @click="$dispatch('close-modal', 'cancel')">Keep appointment</x-admin.button>
                <x-admin.button type="submit" variant="danger">Cancel appointment</x-admin.button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal name="reschedule" title="Reschedule appointment" max-width="max-w-xl">
        <form method="POST" action="{{ route('appointments.reschedule', $a) }}" class="space-y-4"
              x-data="{ date: @js(old('appointment_date', $a->appointment_date->toDateString())), staff: @js(old('staff_id', $a->staff_profile_id)), time: @js(old('start_time', \App\Support\Fmt::time($a->start_time))), slots: [], reason: null, loading: false,
                       async load() { this.loading = true; const r = await fetch('{{ route('appointments.slots', $a) }}?' + new URLSearchParams({ date: this.date, staff_id: this.staff }), { headers: { Accept: 'application/json' } }); const d = await r.json(); this.slots = d.slots ?? []; this.reason = d.closed_reason; this.loading = false; } }"
              x-init="load()">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="rs_date" class="label">Date</label>
                    <input id="rs_date" type="date" name="appointment_date" x-model="date" @change="load()" min="{{ now()->toDateString() }}" class="field" required>
                </div>
                <div>
                    <label for="rs_staff" class="label">Staff</label>
                    <select id="rs_staff" name="staff_id" x-model="staff" @change="load()" class="field">
                        @foreach ($staffOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div>
                <p class="label">Available start times</p>
                <p x-show="loading" class="text-sm text-on-surface-variant">Checking availability…</p>
                <p x-show="!loading && reason" x-text="reason" class="text-sm text-warning"></p>
                <p x-show="!loading && !reason && slots.length === 0" class="text-sm text-on-surface-variant">No free times on this date. You can still enter a custom time below.</p>
                <div class="flex flex-wrap gap-2" x-show="!loading">
                    <template x-for="slot in slots" :key="slot">
                        <button type="button" @click="time = slot" :class="time === slot ? 'bg-primary text-on-primary' : 'bg-surface-container-high hover:bg-primary-container'"
                                class="min-h-10 rounded-full px-4 text-sm font-medium" x-text="slot" :aria-pressed="time === slot"></button>
                    </template>
                </div>
            </div>
            <div>
                <label for="rs_time" class="label">Start time</label>
                <input id="rs_time" type="time" name="start_time" x-model="time" class="field w-40" required>
                <p class="mt-1.5 text-xs text-on-surface-variant">The system re-checks the staff member's schedule and existing bookings before saving.</p>
            </div>
            <div class="flex justify-end gap-2">
                <x-admin.button variant="text" @click="$dispatch('close-modal', 'reschedule')">Close</x-admin.button>
                <x-admin.button type="submit">Save new time</x-admin.button>
            </div>
        </form>
    </x-admin.modal>
</x-admin.layout>
