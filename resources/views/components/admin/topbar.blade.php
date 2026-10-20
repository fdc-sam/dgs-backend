@props(['title' => null])
@php
    $user = auth()->user();
    $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('');
@endphp
<header class="sticky top-0 z-20 border-b border-outline-variant bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-[4.5rem] max-w-screen-2xl items-center gap-3 px-4 sm:px-6 lg:px-12">
        <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-container lg:hidden"
                @click="nav = true" aria-label="Open navigation">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>

        <p class="truncate text-base font-semibold">{{ $title }}</p>

        <div class="ml-auto flex items-center gap-3">
            @if ($user->isManager())
                {{-- Quick customer lookup: name, email or phone. --}}
                <form method="GET" action="{{ route('customers.index') }}" role="search" class="relative hidden w-72 md:block">
                    <span class="material-symbols-outlined pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-outline" aria-hidden="true">search</span>
                    <input type="search" name="search" value="{{ request()->routeIs('customers.index') ? request('search') : '' }}" placeholder="Search customers…" aria-label="Search customers"
                           class="h-11 w-full rounded-xl border border-outline-variant bg-surface-container pl-11 pr-4 text-sm placeholder:text-outline focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10 focus:outline-none">
                </form>
            @endif

            <a href="{{ route('notifications.index') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl border border-outline-variant bg-white hover:bg-surface-container"
               aria-label="Notifications{{ $unreadCount ? " ({$unreadCount} unread)" : '' }}">
                <span class="material-symbols-outlined" style="font-variation-settings: 'wght' 300" aria-hidden="true">notifications</span>
                @if ($unreadCount > 0)
                    <span class="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-danger ring-2 ring-white"></span>
                @endif
            </a>

            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full ring-offset-2 hover:ring-2 hover:ring-outline-variant"
                        @click="open = !open" :aria-expanded="open" aria-haspopup="menu" aria-label="Account menu">
                    @if ($user->profile_photo)
                        <img src="{{ $user->profilePhotoUrl() }}" alt="" class="h-11 w-11 rounded-full object-cover">
                    @else
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary text-sm font-semibold text-on-primary" aria-hidden="true">{{ $initials }}</span>
                    @endif
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false" role="menu"
                     class="absolute right-0 mt-2 w-60 overflow-hidden rounded-2xl border border-outline-variant bg-white py-1 shadow-e2">
                    <div class="border-b border-outline-variant px-4 py-3">
                        <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-xs text-outline">{{ $user->role->label() }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="flex min-h-11 items-center gap-3 px-4 text-sm hover:bg-surface-container" role="menuitem">
                        <span class="material-symbols-outlined" aria-hidden="true">account_circle</span> My account
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex min-h-11 w-full items-center gap-3 px-4 text-left text-sm text-danger hover:bg-danger-container/50" role="menuitem">
                            <span class="material-symbols-outlined" aria-hidden="true">logout</span> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
