<x-admin.layout title="Admin accounts">
    <x-admin.page-header title="Admin accounts" subtitle="Who can sign in to the admin portal and the admin app, and which of them are test accounts.">
        @can('change-setup')
            <x-slot:actions>
                <x-admin.button :href="route('admin-accounts.create')" icon="person_add">New admin</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    <div class="mb-6 flex items-start gap-3 rounded-card border border-outline-variant bg-white px-5 py-4 text-sm text-on-surface-variant">
        <span class="material-symbols-outlined shrink-0 text-gold-strong" aria-hidden="true">science</span>
        <p>A <strong class="text-on-surface">test account</strong> sees only test customers, their bookings, payments, reviews and the walk-ins it
            records, and live accounts never see that data. Test accounts can view the salon setup but not change it.
            Mark customers on their page and stylists on their staff page.</p>
    </div>

    <x-admin.card :padding="false">
        <x-admin.table :headers="['Name', 'Role', 'Status', 'Account type']">
            @foreach ($admins as $admin)
                <tr>
                    <td>
                        <p class="font-medium">{{ $admin->name }} @if ($admin->is(auth()->user()))<span class="text-xs font-normal text-on-surface-variant">(you)</span>@endif</p>
                        <p class="text-xs text-on-surface-variant">{{ $admin->email }}</p>
                    </td>
                    <td class="whitespace-nowrap">{{ $admin->role->label() }}</td>
                    <td><x-admin.badge :tone="$admin->isActive() ? 'success' : 'neutral'">{{ $admin->status->label() }}</x-admin.badge></td>
                    <td>
                        @if ($admin->is(auth()->user()) || ! auth()->user()->can('change-setup'))
                            @if ($admin->is_test)<x-admin.badge tone="warning" icon="science">Test</x-admin.badge>@else<x-admin.badge>Live</x-admin.badge>@endif
                        @else
                            <form method="POST" action="{{ route('admin-accounts.account-type', $admin) }}" class="flex items-center gap-2"
                                  data-confirm="Change {{ $admin->name }}'s account type? They will only see {{ $admin->is_test ? 'live' : 'test' }} data from their next page.">
                                @csrf @method('PUT')
                                <select name="account_type" aria-label="Account type for {{ $admin->name }}" class="field w-36" data-native>
                                    <option value="live" @selected(! $admin->is_test)>Live</option>
                                    <option value="test" @selected($admin->is_test)>Test</option>
                                </select>
                                <x-admin.button type="submit" variant="tonal" size="sm">Save</x-admin.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>
    </x-admin.card>
</x-admin.layout>
