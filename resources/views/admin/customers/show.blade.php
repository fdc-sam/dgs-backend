@php $p = $customer->customerProfile; @endphp
<x-admin.layout :title="$customer->name">
    <x-admin.page-header :title="$customer->name" :back="route('customers.index')" :subtitle="'Customer since '.$customer->created_at->format('F Y')">
        <x-slot:actions>
            @can('changeStatus', $customer)
                <form method="POST" action="{{ route('customers.status', $customer) }}" class="flex items-center gap-2"
                      data-confirm="Change this customer's account status? Suspended customers are signed out of the app.">
                    @csrf
                    <x-admin.select name="status" :options="['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']" :value="$customer->status->value" aria-label="Account status" class="w-40" />
                    <x-admin.button type="submit">Update status</x-admin.button>
                </form>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5">
        <x-admin.stat label="Bookings" :value="$stats['total']" icon="event" />
        <x-admin.stat label="Completed" :value="$stats['completed']" icon="task_alt" tone="success" />
        <x-admin.stat label="Cancelled" :value="$stats['cancelled']" icon="event_busy" tone="warning" />
        <x-admin.stat label="No-shows" :value="$stats['no_show']" icon="person_off" tone="warning" />
        <x-admin.stat label="Lifetime value" :value="'₱'.number_format($stats['value'], 2)" icon="payments" tone="gold" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="Appointment history" :padding="false">
                @if ($appointments->isEmpty())
                    <x-admin.empty-state icon="event" title="No appointments yet" />
                @else
                    <x-admin.table :headers="['Booking', 'When', 'Services', '>Total', 'Status']">
                        @foreach ($appointments as $a)
                            <tr>
                                <td class="min-w-36">
                                    <a href="{{ route('appointments.show', $a) }}" class="whitespace-nowrap font-mono text-[0.6875rem] font-semibold tracking-tight text-primary hover:underline">{{ $a->appointment_number }}</a>
                                    <p class="text-xs text-on-surface-variant">{{ $a->branch->name }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ $a->startsAt()->format('M j, Y') }}
                                    <p class="text-xs text-on-surface-variant">{{ $a->startsAt()->format('g:i A') }}</p>
                                </td>
                                <td>{{ $a->items->pluck('service_name')->join(', ') }}</td>
                                <td class="whitespace-nowrap text-right">₱{{ number_format($a->total_amount, 2) }}</td>
                                <td><x-admin.status-badge :status="$a->status" /></td>
                            </tr>
                        @endforeach
                    </x-admin.table>
                    <x-admin.pagination :paginator="$appointments" />
                @endif
            </x-admin.card>

            <x-admin.card title="Reviews">
                @forelse ($reviews as $r)
                    <div class="border-b border-outline-variant/50 py-3 first:pt-0 last:border-0 last:pb-0">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium">{{ $r->service?->name ?? 'Appointment' }} <span class="text-gold" aria-label="{{ $r->rating }} stars">{{ str_repeat('★', $r->rating) }}</span></p>
                            <x-admin.badge :tone="['approved' => 'success', 'rejected' => 'danger'][$r->status->value] ?? 'warning'">{{ $r->status->label() }}</x-admin.badge>
                        </div>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ $r->review ?: 'No written review.' }}</p>
                    </div>
                @empty
                    <p class="text-sm text-on-surface-variant">No reviews yet.</p>
                @endforelse
            </x-admin.card>
        </div>

        <div class="space-y-6">
            @can('super-admin')
                <x-admin.card title="Test or live" subtitle="Saves as soon as you pick one.">
                    {{-- Picking the other option saves it after its own confirm; cancelling puts the choice back. --}}
                    <form method="POST" action="{{ route('customers.account-type', $customer) }}"
                          x-data="{
                              saved: @js($customer->is_test ? 'test' : 'live'),
                              messages: @js([
                                  'test' => "Make {$customer->name} a test account? They and their bookings move to the test side, and only test accounts will see them.",
                                  'live' => "Make {$customer->name} a live account? They and their bookings move to the live side, and only live accounts will see them.",
                              ]),
                              pick(event) {
                                  const side = event.target.value;
                                  if (side === this.saved) return;
                                  if (window.confirm(this.messages[side])) this.$root.submit();
                                  else this.$root.querySelector(`input[value=${this.saved}]`).checked = true;
                              },
                          }" @change="pick($event)">
                        @csrf
                        <x-admin.account-type :test="$customer->is_test" />
                        <noscript><x-admin.button type="submit" variant="tonal" icon="save" class="mt-4 w-full">Save account type</x-admin.button></noscript>
                    </form>
                </x-admin.card>
            @endcan
            <x-admin.card title="Loyalty card" subtitle="The number on the card the customer bought. Once it is linked here, the card shows in the customer's app.">
                <x-admin.loyalty-card :number="$customer->loyalty_number" :name="$customer->name" :class="$customer->loyalty_number ? 'mb-4' : 'mb-3 opacity-60'" />
                @unless ($customer->loyalty_number)
                    <p class="mb-4 text-sm text-on-surface-variant">No loyalty card linked yet.</p>
                @endunless
                @can('update', $customer)
                    <form method="POST" action="{{ route('customers.loyalty-card', $customer) }}" class="space-y-3">
                        @csrf
                        <x-admin.input name="loyalty_number" :label="$customer->loyalty_number ? 'Change card number' : 'Card number'" inputmode="numeric"
                                       autocomplete="off" placeholder="e.g. LC-0000353" :value="old('loyalty_number')" />
                        <div class="flex flex-wrap gap-2">
                            <x-admin.button type="submit" variant="tonal" size="sm" icon="credit_card">{{ $customer->loyalty_number ? 'Save new number' : 'Link card' }}</x-admin.button>
                        </div>
                    </form>
                    @if ($customer->loyalty_number)
                        <form method="POST" action="{{ route('customers.loyalty-card', $customer) }}" class="mt-2" data-confirm="Remove loyalty card {{ \App\Support\LoyaltyNumber::format($customer->loyalty_number) }} from {{ $customer->name }}?">
                            @csrf
                            <input type="hidden" name="loyalty_number" value="">
                            <x-admin.button type="submit" variant="danger-text" size="sm">Remove card</x-admin.button>
                        </form>
                    @endif
                @endcan
            </x-admin.card>
            <x-admin.card title="Customer QR code">
                <div class="flex flex-col items-center gap-3 text-center">
                    <img src="{{ route('customers.qr', ['customer' => $customer, 'size' => 480]) }}" alt="QR code for {{ $customer->name }}"
                         width="240" class="w-60 max-w-full rounded-xl border border-outline-variant bg-white p-1">
                    <p class="font-mono text-sm font-semibold tracking-wide">{{ $customer->qr_code }}</p>
                    <p class="text-xs text-on-surface-variant">Created with the account and unique to this customer. Search for the code on the Customers page to find them.</p>
                    <x-admin.button :href="route('customers.qr', ['customer' => $customer, 'download' => 1, 'size' => 1000])" variant="tonal" icon="download" size="sm">Download PNG</x-admin.button>
                </div>
            </x-admin.card>
            <x-admin.card title="Contact & profile">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-on-surface-variant">Email</dt><dd class="font-medium">{{ $customer->email }}</dd></div>
                    <div><dt class="text-on-surface-variant">Phone</dt><dd class="font-medium">{{ $customer->phone ?? '—' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Birthday</dt><dd class="font-medium">{{ $p?->date_of_birth?->format('F j') ?? '—' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Gender</dt><dd class="font-medium">{{ $p?->gender ? ucfirst(str_replace('_', ' ', $p->gender)) : '—' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Address</dt><dd class="font-medium">{{ $p?->address ?? '—' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Emergency contact</dt><dd class="font-medium">{{ $p?->emergency_contact_name ? $p->emergency_contact_name.' · '.$p->emergency_contact_phone : '—' }}</dd></div>
                </dl>
            </x-admin.card>
            <x-admin.card title="Completed services">
                @forelse ($completedServices as $s)
                    <div class="flex justify-between py-1.5 text-sm"><span>{{ $s->service_name }}</span><span class="text-on-surface-variant">×{{ $s->times }}</span></div>
                @empty
                    <p class="text-sm text-on-surface-variant">None yet.</p>
                @endforelse
            </x-admin.card>
            <x-admin.card title="Favorites">
                @forelse ($customer->favorites as $f)
                    @php $item = $f->favoritable; @endphp
                    @if ($item)
                        <div class="flex items-center gap-2 py-1.5 text-sm">
                            <span class="material-symbols-outlined text-gold icon-fill" aria-hidden="true">favorite</span>
                            <span>{{ $f->favoritable_type === 'staff' ? $item->user?->name : $item->name }}</span>
                            <span class="ml-auto text-xs text-outline">{{ ucfirst($f->favoritable_type) }}</span>
                        </div>
                    @endif
                @empty
                    <p class="text-sm text-on-surface-variant">No favorites.</p>
                @endforelse
            </x-admin.card>
        </div>
    </div>
</x-admin.layout>
