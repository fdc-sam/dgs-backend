<x-guest.layout title="Sign in">
    <div class="rounded-card border border-outline-variant bg-white p-8">
        <h1 class="text-3xl font-bold tracking-tight">Welcome back</h1>
        <p class="mt-1 text-sm text-on-surface-variant">Sign in to the admin portal.</p>

        <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
            @csrf
            <x-admin.input name="email" type="email" label="Email" autocomplete="username" required autofocus />
            <x-admin.input name="password" type="password" label="Password" autocomplete="current-password" required />
            <x-admin.checkbox name="remember" label="Keep me signed in" />
            <x-admin.button type="submit" class="w-full">Sign in</x-admin.button>
        </form>
    </div>
    @if (app()->environment('local'))
        <p class="mt-6 text-center text-xs text-on-surface-variant">Local demo: admin@example.com / password (development data only)</p>
    @endif
</x-guest.layout>
