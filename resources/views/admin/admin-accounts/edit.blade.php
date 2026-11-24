{{-- One admin portal / admin app login (Admin accounts → a row). Your own role, status and account type are locked. --}}
<x-admin.layout :title="$admin->name">
    <x-admin.page-header :title="$admin->name" :back="route('admin-accounts.index')"
                         :subtitle="$admin->role->label().' · '.($admin->is_test ? 'Test account' : 'Live account')" />

    <form method="POST" action="{{ route('admin-accounts.update', $admin) }}" class="max-w-3xl space-y-6">
        @csrf @method('PUT')
        <fieldset class="min-w-0 space-y-6" @cannot('change-setup') disabled @endcannot>
            <x-admin.card title="Sign-in">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.input name="name" label="Full name" :value="$admin->name" required maxlength="120" class="sm:col-span-2" />
                    <x-admin.input name="email" type="email" label="Email (sign-in)" :value="$admin->email" required autocomplete="off" class="sm:col-span-2" />
                </div>
            </x-admin.card>

            <x-admin.card title="Access">
                @if ($self)
                    <p class="mb-4 flex items-start gap-2 rounded-2xl bg-surface-container px-4 py-3 text-sm text-on-surface-variant">
                        <span class="material-symbols-outlined shrink-0 text-base" aria-hidden="true">lock</span>
                        You can't change your own role, status or account type. Ask another super admin.
                    </p>
                @endif
                <fieldset class="min-w-0 space-y-5" @disabled($self)>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-admin.select name="role" label="Role" :value="$admin->role->value" required
                                        :options="['admin' => 'Admin', 'super_admin' => 'Super Admin', 'cashier' => 'Cashier']"
                                        hint="Cashiers can access Payments and view Referrals. Admins can manage the salon portal; super admins can also change Settings and admin accounts." />
                        <x-admin.select name="status" label="Status" :value="$admin->status->value" required
                                        :options="collect(\App\Enums\UserStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()"
                                        hint="Only active accounts can sign in. Turning an account off also signs it out of the admin app." />
                    </div>
                    <x-admin.account-type :test="$admin->is_test" />
                </fieldset>
            </x-admin.card>

            <x-admin.card title="Change password">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.input name="password" type="password" label="New password" autocomplete="new-password"
                                   hint="Leave blank to keep the current password. At least 8 characters with letters and numbers." />
                    <x-admin.input name="password_confirmation" type="password" label="Repeat new password" autocomplete="new-password" />
                </div>
                @unless ($self)
                    <p class="mt-3 text-xs text-on-surface-variant">A new password signs {{ $admin->name }} out of the admin app. Share it with them privately.</p>
                @endunless
            </x-admin.card>
        </fieldset>

        <div class="flex justify-end gap-2">
            <x-admin.button :href="route('admin-accounts.index')" variant="text">Cancel</x-admin.button>
            @can('change-setup')
                <x-admin.button type="submit" icon="save">Save changes</x-admin.button>
            @endcan
        </div>
    </form>
</x-admin.layout>
