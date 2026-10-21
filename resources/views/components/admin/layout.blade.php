@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ $salonName }} Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @include('partials.share-meta')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,300..600,0..1,0&display=block">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
{{-- scrolled: the page heading has scrolled away, so the phone top bar swaps the brand for the page title. --}}
<body class="theme-admin min-h-screen" x-data="{ nav: false, scrolled: window.scrollY > 72 }" @scroll.window.passive="scrolled = window.scrollY > 72"
      @keydown.escape.window="nav = false" :class="nav && 'max-lg:overflow-hidden'">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary">Skip to content</a>

    <div class="flex min-h-screen">
        <x-admin.sidebar />

        <div class="flex min-w-0 flex-1 flex-col lg:pl-72">
            <x-admin.topbar :title="$title" />
            @if (auth()->user()->is_test)
                {{-- Test accounts only ever see test data; say so on every page. --}}
                <div class="border-b border-warning/30 bg-warning-container text-warning" role="note">
                    <p class="mx-auto flex max-w-screen-2xl items-start gap-2.5 px-4 py-2.5 text-sm sm:items-center sm:px-6 lg:px-12">
                        <span class="material-symbols-outlined shrink-0 icon-fill" aria-hidden="true">science</span>
                        <span><strong class="font-semibold">Test account.</strong> You see test customers, bookings and reports only. The salon setup is view-only.</span>
                    </p>
                </div>
            @endif

            <main id="main" class="mx-auto w-full max-w-screen-2xl flex-1 px-4 pb-[max(4rem,env(safe-area-inset-bottom))] pt-6 sm:px-6 sm:pt-8 lg:px-12 lg:pt-10">
                <x-admin.flash />
                {{ $slot }}
            </main>
        </div>
    </div>
    @stack('scripts')
</body>
</html>
