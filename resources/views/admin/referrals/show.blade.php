@php
    use App\Enums\ReferralStatus;
    use App\Support\Fmt;
    $canEdit = ! in_array($referral->status, [ReferralStatus::Completed, ReferralStatus::Declined], true);
@endphp
<x-admin.layout :title="$referral->name">
    <x-admin.page-header :title="$referral->name" :back="route('referrals.index')" subtitle="Referral details">
        <x-slot:actions>
            <x-admin.badge :tone="['pending' => 'warning', 'accepted' => 'info', 'in_progress' => 'primary', 'completed' => 'success', 'declined' => 'danger'][$referral->status->value]">{{ $referral->status->label() }}</x-admin.badge>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card title="Referred person">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div><dt class="text-sm text-on-surface-variant">Name</dt><dd class="font-medium">{{ $referral->name }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Phone</dt><dd class="font-medium"><a class="hover:underline" href="tel:{{ $referral->phone }}">{{ $referral->phone }}</a></dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Referred on</dt><dd class="font-medium">{{ $referral->created_at->format('l, F j, Y · g:i A') }}</dd></div>
                    <div><dt class="text-sm text-on-surface-variant">Customer visits</dt><dd class="font-medium">{{ $visit['completed'] }} completed {{ \Illuminate\Support\Str::plural('visit', $visit['completed']) }}</dd></div>
                    @if ($referral->notes)
                        <div class="sm:col-span-2"><dt class="text-sm text-on-surface-variant">Customer's note</dt><dd class="mt-1 rounded-xl bg-surface-container px-4 py-3 text-sm">{{ $referral->notes }}</dd></div>
                    @endif
                </dl>
                @if ($visit['customer'] && auth()->user()->isManager())
                    <x-admin.button :href="route('customers.show', $visit['customer'])" variant="text" size="sm" class="-ml-3 mt-3">View customer profile</x-admin.button>
                @endif
            </x-admin.card>

            <x-admin.card title="Services and charges" :padding="false">
                @if ($canEdit)
                    @can('manage')
                        <x-slot:actions>
                            <x-admin.button type="button" variant="tonal" icon="add" @click="$dispatch('open-modal', 'referral-add-service')">Add service or charge</x-admin.button>
                        </x-slot:actions>
                    @endcan
                @endif
                @if ($errors->any())
                    <div class="mx-5 mt-4 rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</div>
                @endif
                <x-admin.table :headers="['Service / charge', 'Stylist', 'Quantity', '>Price', 'Actions']"
                               :mobile-order="['Service / charge' => 0, 'Price' => 1, 'Quantity' => 2, 'Stylist' => 3, 'Actions' => 4]"
                               :mobile-summary="['Quantity', 'Stylist']"
                               :mobile-labels="['Price' => 'Service price']">
                    @foreach ($referral->items as $item)
                        <tr>
                            <td class="font-medium">{{ $item->service_name }} @unless ($item->service_id)<span class="ml-1 text-xs font-normal text-on-surface-variant">Custom charge</span>@endunless</td>
                            <td>{{ $item->staff?->user?->name ?? 'Any qualified stylist' }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td class="text-right">₱{{ number_format((float) $item->subtotal, 2) }}</td>
                            <td class="text-right">
                                @can('manage')
                                    @if ($item->service_id && $canEdit)
                                        <button type="button" class="font-medium text-primary" @click="$dispatch('open-modal', 'referral-item-{{ $item->id }}')">Edit</button>
                                    @endif
                                    @if ($canEdit)
                                        <form method="POST" action="{{ route('referrals.items.destroy', [$referral, $item]) }}" class="ml-3 inline" data-confirm="Remove {{ $item->service_name }} from this referral?">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="font-medium text-danger">Remove</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    <tr><td colspan="4" class="text-right font-semibold">Total</td><td class="text-right text-lg font-semibold">₱{{ number_format((float) $referral->items->sum('subtotal'), 2) }}</td></tr>
                </x-admin.table>
            </x-admin.card>
        </div>

        <div class="space-y-6">
            <x-admin.card title="Referral status">
                <p class="text-sm text-on-surface-variant">Referred by</p>
                @if ($referral->referrer && ! $referral->referrer->trashed())
                    <a href="{{ route('customers.show', $referral->referrer) }}" class="font-semibold hover:underline">{{ $referral->referrer->name }}</a>
                @else
                    <p class="font-semibold">{{ $referral->referrer?->name ?? 'Deleted customer' }}</p>
                @endif
                <p class="text-sm text-on-surface-variant">{{ $referral->referrer?->phone ?? '—' }}</p>
                @if ($referral->admin_note)
                    <p class="mt-3 rounded-xl bg-surface-container px-4 py-3 text-sm">{{ $referral->admin_note }}</p>
                @endif
                @can('manage')
                <div class="mt-4 space-y-2">
                    @if ($referral->status === ReferralStatus::Pending)
                        <form method="POST" action="{{ route('referrals.status', $referral) }}">@csrf<input type="hidden" name="status" value="accepted"><x-admin.button type="submit" variant="tonal" icon="check" class="w-full">Accept referral</x-admin.button></form>
                    @endif
                    @if ($referral->status === ReferralStatus::Accepted)
                        <form method="POST" action="{{ route('referrals.status', $referral) }}">@csrf<input type="hidden" name="status" value="in_progress"><x-admin.button type="submit" variant="tonal" icon="autorenew" class="w-full">Mark in progress</x-admin.button></form>
                    @endif
                    @if ($referral->status === ReferralStatus::InProgress)
                        <x-admin.button type="button" icon="task_alt" class="w-full" @click="$dispatch('open-modal', 'referral-complete')">Complete referral</x-admin.button>
                    @endif
                    @if (in_array($referral->status, [ReferralStatus::Pending, ReferralStatus::Accepted, ReferralStatus::InProgress], true))
                        <x-admin.button type="button" variant="danger-text" class="w-full" @click="$dispatch('open-modal', 'referral-decline')">Mark not eligible</x-admin.button>
                    @endif
                    @if ($referral->status === ReferralStatus::Declined)
                        <form method="POST" action="{{ route('referrals.status', $referral) }}">@csrf<input type="hidden" name="status" value="pending"><x-admin.button type="submit" variant="tonal" class="w-full">Reopen referral</x-admin.button></form>
                    @endif
                </div>
                @endcan
            </x-admin.card>

            @if ($referral->status === ReferralStatus::Completed)
                <x-admin.card title="Reward given">
                    <p class="text-2xl font-semibold">{{ Fmt::peso($referral->reward_amount) }}</p>
                    <p class="mt-1 text-sm text-on-surface-variant">@if ($referral->reward_paid_directly) Sent to GCash @if ($referral->reward_reference) · Ref {{ $referral->reward_reference }} @endif @else Added to the referrer's app earnings @endif</p>
                    <p class="mt-1 text-sm text-on-surface-variant">{{ $referral->rewarded_at?->format('M j, Y · g:i A') }}</p>
                </x-admin.card>
            @endif

            <x-admin.card title="Visit history">
                @if ($visit['completed'] > 0)
                    <p class="flex items-center gap-2 text-sm font-medium text-success"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>{{ $visit['completed'] }} completed {{ \Illuminate\Support\Str::plural('visit', $visit['completed']) }}</p>
                @else
                    <p class="text-sm text-on-surface-variant">No completed visit recorded yet.</p>
                @endif
                @if ($visit['customer'])
                    <p class="mt-2 text-xs text-on-surface-variant">Customer app account since {{ $visit['customer']->created_at->format('M Y') }}.</p>
                @endif
            </x-admin.card>
        </div>
    </div>

    @if ($canEdit)
        @can('manage')
            @foreach ($referral->items->whereNotNull('service_id') as $item)
                <x-admin.modal :name="'referral-item-'.$item->id" title="Edit service">
                    <form method="POST" action="{{ route('referrals.items.update', [$referral, $item]) }}" class="referral-item-editor space-y-4" data-current-staff="{{ $item->staff_profile_id }}">
                        @csrf @method('PUT')
                        <div>
                            <label for="service-{{ $item->id }}" class="label">Service</label>
                            <select id="service-{{ $item->id }}" name="service_id" class="field referral-item-service" required>
                                @foreach ($services as $service)<option value="{{ $service->id }}" @selected($service->id === $item->service_id)>{{ $service->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="stylist-{{ $item->id }}" class="label">Stylist</label>
                            <select id="stylist-{{ $item->id }}" name="staff_profile_id" class="field referral-item-staff"><option value="">Any qualified stylist</option></select>
                        </div>
                        <p class="text-sm text-on-surface-variant">The service price updates to the current catalog price when you save.</p>
                        <div class="flex justify-end gap-2"><x-admin.button type="button" variant="text" @click="$dispatch('close-modal', 'referral-item-{{ $item->id }}')">Cancel</x-admin.button><x-admin.button type="submit">Save changes</x-admin.button></div>
                    </form>
                </x-admin.modal>
            @endforeach

            <x-admin.modal name="referral-add-service" title="Add service or charge">
                <form method="POST" action="{{ route('referrals.items.store', $referral) }}" class="referral-add-editor space-y-4">
                    @csrf
                    <div>
                        <label for="referral-item-type" class="label">What would you like to add?</label>
                        <select id="referral-item-type" name="item_type" class="field referral-add-type" required>
                            <option value="service" @selected(old('item_type', 'service') === 'service')>Catalog service</option>
                            <option value="custom" @selected(old('item_type') === 'custom')>Custom service or charge</option>
                        </select>
                    </div>
                    <div data-service-fields>
                        <label for="referral-add-service" class="label">Service</label>
                        <select id="referral-add-service" name="service_id" class="field referral-add-service" required>
                            <option value="">Choose a service</option>
                            @foreach ($services as $service)<option value="{{ $service->id }}" @selected(old('service_id') === $service->id)>{{ $service->name }}</option>@endforeach
                        </select>
                    </div>
                    <div data-custom-fields class="space-y-4">
                        <x-admin.input name="service_name" label="Service or charge name" placeholder="e.g. Long hair surcharge" maxlength="255" required />
                        <x-admin.input name="unit_price" type="number" label="Price" prefix="₱" min="0.01" max="99999999.99" step="0.01" placeholder="0.00" required />
                    </div>
                    <div data-service-fields>
                        <label for="referral-add-stylist" class="label">Stylist</label>
                        <select id="referral-add-stylist" name="staff_profile_id" class="field referral-add-staff"><option value="">Any qualified stylist</option></select>
                    </div>
                    <x-admin.input name="quantity" type="number" label="Quantity" min="1" max="1000" :value="old('quantity', 1)" required />
                    <div class="flex justify-end gap-2"><x-admin.button type="button" variant="text" @click="$dispatch('close-modal', 'referral-add-service')">Cancel</x-admin.button><x-admin.button type="submit">Add to referral</x-admin.button></div>
                </form>
            </x-admin.modal>

            <script>
                const referralStaff = @js($staff->map(fn ($profile) => [
                    'id' => $profile->id,
                    'name' => $profile->user?->name.($profile->position ? ' · '.$profile->position : ''),
                    'serviceIds' => $profile->services->pluck('id')->all(),
                ])->values());
                document.querySelectorAll('.referral-item-editor').forEach((form) => {
                    const service = form.querySelector('.referral-item-service');
                    const stylist = form.querySelector('.referral-item-staff');
                    const currentStaff = form.dataset.currentStaff || '';
                    const renderStylists = () => {
                        const eligible = referralStaff.filter((person) => person.serviceIds.includes(service.value));
                        stylist.replaceChildren(new Option('Any qualified stylist', ''));
                        eligible.forEach((person) => stylist.add(new Option(person.name, person.id)));
                        stylist.value = eligible.some((person) => person.id === currentStaff) ? currentStaff : '';
                    };
                    service.addEventListener('change', renderStylists);
                    renderStylists();
                });
                document.querySelectorAll('.referral-add-editor').forEach((form) => {
                    const type = form.querySelector('.referral-add-type');
                    const service = form.querySelector('.referral-add-service');
                    const stylist = form.querySelector('.referral-add-staff');
                    const serviceFields = form.querySelectorAll('[data-service-fields]');
                    const customFields = form.querySelector('[data-custom-fields]');
                    const renderStylists = () => {
                        const eligible = referralStaff.filter((person) => person.serviceIds.includes(service.value));
                        stylist.replaceChildren(new Option('Any qualified stylist', ''));
                        eligible.forEach((person) => stylist.add(new Option(person.name, person.id)));
                    };
                    const renderType = () => {
                        const catalog = type.value === 'service';
                        serviceFields.forEach((group) => group.querySelectorAll('select').forEach((input) => { input.disabled = !catalog; }));
                        customFields.querySelectorAll('input').forEach((input) => { input.disabled = catalog; });
                        renderStylists();
                    };
                    type.addEventListener('change', renderType);
                    service.addEventListener('change', renderStylists);
                    renderType();
                });
            </script>
        @endcan
    @endif

    @if ($referral->status === ReferralStatus::InProgress)
        @can('manage')
        <x-admin.modal name="referral-complete" title="Complete referral" max-width="max-w-xl">
            <form method="POST" action="{{ route('referrals.complete', $referral) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">The reward is added to {{ $referral->referrer?->name ?? 'the referrer' }}'s app earnings, and they will be notified.</p>
                <x-admin.input name="amount" type="number" step="0.01" min="1" max="100000" label="Reward the referrer earns" prefix="₱" :value="old('amount', $suggestion['amount'] ?? '')" required />
                @if ($suggestion)
                    <p class="-mt-2 text-xs text-on-surface-variant">Suggested from selected services: {{ $suggestion['summary'] }}.</p>
                @else
                    <p class="-mt-2 text-xs text-on-surface-variant">No matching reward is configured for the selected services.</p>
                @endif
                <x-admin.input name="note" label="Message to the referrer (optional)" placeholder="Thank you for recommending us!" />
                <div class="flex justify-end gap-2"><x-admin.button type="button" variant="text" @click="$dispatch('close-modal', 'referral-complete')">Close</x-admin.button><x-admin.button type="submit" icon="task_alt">Complete and add reward</x-admin.button></div>
            </form>
        </x-admin.modal>
        @endcan
    @endif

    @if (in_array($referral->status, [ReferralStatus::Pending, ReferralStatus::Accepted, ReferralStatus::InProgress], true))
        @can('manage')
        <x-admin.modal name="referral-decline" title="Not eligible for a reward">
            <form method="POST" action="{{ route('referrals.status', $referral) }}" class="space-y-4">
                @csrf <input type="hidden" name="status" value="declined">
                <p class="text-sm text-on-surface-variant">{{ $referral->name }}'s referral will not earn a reward. Their number can be referred again later.</p>
                <x-admin.textarea name="note" label="Reason for the customer (optional)" rows="2" placeholder="Already a regular customer" />
                <div class="flex justify-end gap-2"><x-admin.button type="button" variant="text" @click="$dispatch('close-modal', 'referral-decline')">Close</x-admin.button><x-admin.button type="submit" variant="danger">Mark not eligible</x-admin.button></div>
            </form>
        </x-admin.modal>
        @endcan
    @endif
</x-admin.layout>
