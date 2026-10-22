@props(['title' => null])
@php
    $user = auth()->user();
    $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('');
    $searchValue = request()->routeIs('customers.index') ? request('search') : '';
@endphp
<header class="sticky top-0 z-20 border-b border-outline-variant bg-white/90 pt-[env(safe-area-inset-top)] backdrop-blur" x-data="{ search: false }">
    <div class="mx-auto flex h-14 max-w-screen-2xl items-center gap-1 px-2 sm:gap-3 sm:px-6 lg:h-[4.5rem] lg:px-12">
        <button type="button" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl hover:bg-surface-container lg:hidden"
                @click="nav = true" aria-label="Open navigation">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>

        {{-- Phones show the brand until the page heading scrolls away, then the page title; desktop always shows the title. --}}
        <div class="relative min-w-0 flex-1 self-stretch">
            <a href="{{ route('dashboard') }}" class="absolute inset-0 flex items-center gap-2.5 transition duration-200 lg:hidden"
               :class="scrolled && 'pointer-events-none -translate-y-2 opacity-0'" :tabindex="scrolled ? -1 : 0">
                <img src="{{ $logoUrl }}" alt="" class="h-8 w-8 shrink-0 rounded-lg bg-white object-contain p-px ring-1 ring-outline-variant">
                <span class="truncate text-[0.9375rem] font-bold tracking-tight">{{ $salonName }}</span>
            </a>
            <p class="absolute inset-0 flex translate-y-2 items-center opacity-0 transition duration-200 lg:static lg:h-full lg:translate-y-0 lg:opacity-100"
               :class="{ 'translate-y-2 opacity-0': !scrolled }" @if (! $title) hidden @endif>
                <span class="truncate text-base font-semibold">{{ $title }}</span>
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-1 sm:gap-3">
            @if ($user->isManager())
                {{-- Quick customer lookup: name, email or phone. Phones open it as a row under the bar. --}}
                <form method="GET" action="{{ route('customers.index') }}" role="search" class="relative hidden w-72 md:block">
                    <span class="material-symbols-outlined pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-outline" aria-hidden="true">search</span>
                    <input type="search" name="search" value="{{ $searchValue }}" placeholder="Search customers…" aria-label="Search customers"
                           class="h-11 w-full rounded-xl border border-outline-variant bg-surface-container pl-11 pr-4 text-sm placeholder:text-outline focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10 focus:outline-none">
                </form>
                <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-container md:hidden"
                        @click="search = !search; search && $nextTick(() => $refs.search.focus())" :aria-expanded="search" aria-controls="mobile-search" aria-label="Search customers">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'wght' 300" aria-hidden="true" x-text="search ? 'close' : 'search'">search</span>
                </button>
            @endif

            <a href="{{ route('notifications.index') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-container md:border md:border-outline-variant md:bg-white"
               aria-label="Notifications{{ $unreadCount ? " ({$unreadCount} unread)" : '' }}">
                <span class="material-symbols-outlined" style="font-variation-settings: 'wght' 300" aria-hidden="true">notifications</span>
                <span data-live-badge="unread" @if ($unreadCount <= 0) hidden @endif class="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-danger ring-2 ring-white"></span>
            </a>

            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full ring-offset-2 hover:ring-2 hover:ring-outline-variant"
                        @click="open = !open" :aria-expanded="open" aria-haspopup="menu" aria-label="Account menu">
                    @if ($user->profile_photo)
                        <img src="{{ $user->profilePhotoUrl() }}" alt="" class="h-9 w-9 rounded-full object-cover lg:h-11 lg:w-11">
                    @else
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-xs font-semibold text-on-primary lg:h-11 lg:w-11 lg:text-sm" aria-hidden="true">{{ $initials }}</span>
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

    @if ($user->isManager())
        <form id="mobile-search" method="GET" action="{{ route('customers.index') }}" role="search" class="px-4 pb-3 md:hidden" x-show="search" x-cloak x-collapse>
            <div class="relative">
                <span class="material-symbols-outlined pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-outline" aria-hidden="true">search</span>
                <input x-ref="search" type="search" name="search" value="{{ $searchValue }}" placeholder="Search customers…" aria-label="Search customers" enterkeyhint="search"
                       class="h-11 w-full rounded-xl border border-outline-variant bg-surface-container pl-11 pr-4 text-base placeholder:text-outline focus:border-primary focus:bg-white focus:ring-2 focus:ring-primary/10 focus:outline-none">
            </div>
        </form>
    @endif
</header>
