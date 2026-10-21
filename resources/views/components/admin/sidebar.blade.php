@php
    $user = auth()->user();
    $manager = $user->isManager();
    $sections = [
        'Overview' => [
            ['dashboard', 'Dashboard', 'space_dashboard', true],
            ['calendar', 'Calendar', 'calendar_month', true],
            ['appointments.index', 'Appointments', 'event_available', true, 'appointments.*'],
            ['walk-ins.index', 'Walk-in Customers', 'how_to_reg', $manager, 'walk-ins.*'],
            ['payments.index', 'Payments', 'payments', $manager, 'payments.*'],
        ],
        'Catalog' => [
            ['services.index', 'Services', 'content_cut', $manager, 'services.*'],
            ['categories.index', 'Categories', 'category', $manager, 'categories.*'],
            ['products.index', 'Inventory', 'inventory_2', $manager, 'products.*'],
            ['promotions.index', 'Promotions', 'sell', $manager, 'promotions.*'],
            ['achievements.index', 'Achievements', 'emoji_events', $manager, 'achievements.*'],
        ],
        'People' => [
            ['customers.index', 'Customers', 'groups', $manager, 'customers.*'],
            ['staff.index', 'Staff', 'badge', $manager, 'staff.*'],
            ['my-schedule', 'My Schedule', 'schedule', $user->isStaff()],
            ['branches.index', 'Branches', 'storefront', $manager, 'branches.*'],
            ['reviews.index', 'Reviews', 'reviews', $manager, 'reviews.*'],
        ],
        'Business' => [
            ['reports.index', 'Reports', 'monitoring', $manager, 'reports.*'],
            ['notifications.index', 'Notifications', 'notifications', true, 'notifications.*'],
            ['settings.edit', 'Settings', 'settings', $user->isSuperAdmin(), 'settings.*'],
        ],
    ];
@endphp

@php
    $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->join('');
@endphp

{{-- Mobile scrim --}}
<div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-on-surface/40 lg:hidden" @click="nav = false" aria-hidden="true"></div>

<aside class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-outline-variant bg-white pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] transition-transform duration-300 ease-out lg:translate-x-0"
       :class="nav && 'translate-x-0 shadow-e2'" aria-label="Main navigation">
    <div class="flex items-center gap-2 pr-3 lg:pr-0">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 items-center gap-3 px-6 pb-5 pt-6 max-lg:pl-5">
            <img src="{{ $logoUrl }}" alt="" class="h-11 w-11 rounded-xl bg-white object-contain p-0.5 ring-1 ring-outline-variant">
            <span class="min-w-0">
                <span class="block truncate text-lg font-bold leading-tight tracking-tight">{{ $salonName }}</span>
                <span class="block text-xs text-outline">Admin portal</span>
            </span>
        </a>
        <button type="button" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container lg:hidden"
                @click="nav = false" aria-label="Close navigation">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-4 pb-6 pt-2">
        @foreach ($sections as $section => $links)
            @php $visible = array_filter($links, fn ($l) => $l[3]); @endphp
            @if ($visible)
                <div>
                    <p class="eyebrow px-3.5 pb-2">{{ $section }}</p>
                    <ul class="space-y-1">
                        @foreach ($visible as $link)
                            @php $active = request()->routeIs($link[4] ?? $link[0]); @endphp
                            <li>
                                <a href="{{ route($link[0]) }}" @class(['nav-link', 'nav-link-active' => $active]) @if ($active) aria-current="page" @endif>
                                    <span class="material-symbols-outlined nav-icon" aria-hidden="true">{{ $link[2] }}</span>
                                    <span class="flex-1">{{ $link[1] }}</span>
                                    @if ($link[0] === 'notifications.index' && $unreadCount > 0)
                                        <span class="rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-on-surface">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                                    @endif
                                    @if ($link[0] === 'products.index' && $lowStockCount > 0)
                                        <span class="rounded-full bg-warning-container px-2 py-0.5 text-xs font-semibold text-warning" title="Low or out of stock" aria-label="{{ $lowStockCount }} low or out of stock">{{ $lowStockCount > 99 ? '99+' : $lowStockCount }}</span>
                                    @endif
                                    @if ($link[0] === 'payments.index' && $paymentsToVerify > 0)
                                        <span class="rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-on-surface" aria-label="{{ $paymentsToVerify }} to verify">{{ $paymentsToVerify > 99 ? '99+' : $paymentsToVerify }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>

    <a href="{{ route('profile.edit') }}" class="mx-4 mb-4 flex items-center gap-3 border-t border-outline-variant px-2 pt-4 hover:opacity-80">
        @if ($user->profile_photo)
            <img src="{{ $user->profilePhotoUrl() }}" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover">
        @else
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gold text-sm font-bold text-on-surface" aria-hidden="true">{{ $initials }}</span>
        @endif
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ $user->name }}</span>
            <span class="block truncate text-xs text-outline">{{ $user->role->label() }}</span>
        </span>
    </a>
</aside>
