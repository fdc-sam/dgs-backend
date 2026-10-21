<x-admin.layout title="New admin">
    <x-admin.page-header title="New admin" :back="route('admin-accounts.index')"
                         subtitle="A sign-in for the admin portal and the DGS Admin app. Share the password with them privately." />

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
                <x-admin.select name="role" label="Role" :options="['admin' => 'Admin', 'super_admin' => 'Super Admin']" value="admin" required
                                hint="Admins run bookings, customers, staff and the catalog. Super admins can also change Settings and admin accounts." />
                <x-admin.account-type />
            </div>
        </x-admin.card>

        <div class="flex justify-end gap-2">
            <x-admin.button :href="route('admin-accounts.index')" variant="text">Cancel</x-admin.button>
            <x-admin.button type="submit" icon="person_add">Create admin</x-admin.button>
        </div>
    </form>
</x-admin.layout>
