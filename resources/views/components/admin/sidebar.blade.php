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

{{-- Mobile scrim --}}
<div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-on-surface/40 lg:hidden" @click="nav = false" aria-hidden="true"></div>

<aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-outline-variant bg-surface-container transition-transform lg:translate-x-0"
       :class="nav && 'translate-x-0'" aria-label="Main navigation">
    <div class="flex items-center gap-3 px-6 py-5">
        <img src="{{ $logoUrl }}" alt="" class="h-11 w-11 rounded-xl bg-white object-contain p-0.5 shadow-e1">
        <div class="min-w-0">
            <p class="truncate text-base font-semibold leading-tight">{{ $salonName }}</p>
            <p class="text-xs text-on-surface-variant">Admin portal</p>
        </div>
    </div>

    <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-6">
        @foreach ($sections as $section => $links)
            @php $visible = array_filter($links, fn ($l) => $l[3]); @endphp
            @if ($visible)
                <div>
                    <p class="px-4 pb-1 text-xs font-semibold uppercase tracking-wider text-outline">{{ $section }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($visible as $link)
                            @php $active = request()->routeIs($link[4] ?? $link[0]); @endphp
                            <li>
                                <a href="{{ route($link[0]) }}" @class(['nav-link', 'nav-link-active' => $active]) @if ($active) aria-current="page" @endif>
                                    <span @class(['material-symbols-outlined', 'icon-fill' => $active]) aria-hidden="true">{{ $link[2] }}</span>
                                    <span class="flex-1">{{ $link[1] }}</span>
                                    @if ($link[0] === 'notifications.index' && $unreadCount > 0)
                                        <span class="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-on-primary">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                                    @endif
                                    @if ($link[0] === 'products.index' && $lowStockCount > 0)
                                        <span class="rounded-full bg-warning-container px-2 py-0.5 text-xs font-semibold text-warning" title="Low or out of stock" aria-label="{{ $lowStockCount }} low or out of stock">{{ $lowStockCount > 99 ? '99+' : $lowStockCount }}</span>
                                    @endif
                                    @if ($link[0] === 'payments.index' && $paymentsToVerify > 0)
                                        <span class="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-on-primary" aria-label="{{ $paymentsToVerify }} to verify">{{ $paymentsToVerify > 99 ? '99+' : $paymentsToVerify }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>
</aside>
