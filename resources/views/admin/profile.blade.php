<x-admin.layout title="My account">
    <x-admin.page-header title="My account" />
    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <x-admin.card title="Profile">
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf @method('PUT')
                <x-admin.input name="name" label="Name" :value="$user->name" required />
                <x-admin.input name="email" type="email" label="Email" :value="$user->email" required />
                <x-admin.input name="phone" label="Phone" :value="$user->phone" />
                <x-admin.image-field name="photo" label="Photo" :current="$user->profilePhotoUrl()" />
                <x-admin.button type="submit">Save</x-admin.button>
            </form>
        </x-admin.card>
        <x-admin.card title="Change password">
            <form method="POST" action="{{ route('profile.password') }}" class="space-y-5">
                @csrf @method('PUT')
                <x-admin.input name="current_password" type="password" label="Current password" autocomplete="current-password" required />
                <x-admin.input name="password" type="password" label="New password" autocomplete="new-password" required hint="At least 8 characters with letters and numbers." />
                <x-admin.input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required />
                <x-admin.button type="submit">Change password</x-admin.button>
            </form>
        </x-admin.card>
    </div>
</x-admin.layout>
