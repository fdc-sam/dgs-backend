<x-admin.layout title="New admin">
        <x-admin.page-header title="New account" :back="route('admin-accounts.index')"
                         subtitle="Create an admin or cashier sign-in. Share the password with them privately." />

    <form method="POST" action="{{ route('admin-accounts.store') }}" class="max-w-3xl space-y-6">
        @csrf
        <x-admin.card title="Sign-in">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.input name="name" label="Full name" required maxlength="120" class="sm:col-span-2" />
                <x-admin.input name="email" type="email" label="Email (sign-in)" required autocomplete="off" class="sm:col-span-2" />
                <x-admin.input name="password" type="password" label="Password" required autocomplete="new-password" hint="At least 8 characters with letters and numbers." />
                <x-admin.input name="password_confirmation" type="password" label="Repeat password" required autocomplete="new-password" />
            </div>
        </x-admin.card>

        <x-admin.card title="Access">
            <div class="space-y-5">
                <x-admin.select name="role" label="Role" :options="['admin' => 'Admin', 'super_admin' => 'Super Admin', 'cashier' => 'Cashier']" value="admin" required
                                hint="Cashiers can access Payments and view Referrals. Admins can manage the salon portal; super admins can also change Settings and admin accounts." />
                <x-admin.account-type />
            </div>
        </x-admin.card>

        <div class="flex justify-end gap-2">
            <x-admin.button :href="route('admin-accounts.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="person_add">Create account</x-admin.button>
        </div>
    </form>
</x-admin.layout>
