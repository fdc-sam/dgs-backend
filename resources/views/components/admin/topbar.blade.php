@props(['title' => null])
<header class="sticky top-0 z-20 border-b border-outline-variant/70 bg-surface/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-surface-container-high lg:hidden"
                @click="nav = true" aria-label="Open navigation">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>

        <p class="truncate text-lg font-semibold">{{ $title }}</p>

        <div class="ml-auto flex items-center gap-1">
            <a href="{{ route('notifications.index') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-surface-container-high"
               aria-label="Notifications{{ $unreadCount ? " ({$unreadCount} unread)" : '' }}">
                <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
                @if ($unreadCount > 0)
                    <span class="absolute right-2.5 top-2.5 h-2.5 w-2.5 rounded-full bg-danger ring-2 ring-surface"></span>
                @endif
            </a>

            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                <button type="button" class="flex min-h-11 items-center gap-2 rounded-full py-1 pl-1 pr-3 hover:bg-surface-container-high"
                        @click="open = !open" :aria-expanded="open" aria-haspopup="menu">
                    @if (auth()->user()->profile_photo)
                        <img src="{{ auth()->user()->profilePhotoUrl() }}" alt="" class="h-9 w-9 rounded-full object-cover">
                    @else
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-container text-sm font-semibold text-on-primary-container" aria-hidden="true">
                            {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('') }}
                        </span>
                    @endif
                    <span class="hidden text-left sm:block">
                        <span class="block text-sm font-medium leading-tight">{{ auth()->user()->name }}</span>
                        <span class="block text-xs text-on-surface-variant">{{ auth()->user()->role->label() }}</span>
                    </span>
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">expand_more</span>
                </button>
                <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false" role="menu"
                     class="absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-outline-variant bg-white py-1 shadow-e2">
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
