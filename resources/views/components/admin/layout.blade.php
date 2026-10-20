@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ $salonName }} Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @include('partials.share-meta')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,300..600,0..1,0&display=block">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="theme-admin min-h-screen" x-data="{ nav: false }">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary">Skip to content</a>

    <div class="flex min-h-screen">
        <x-admin.sidebar />

        <div class="flex min-w-0 flex-1 flex-col lg:pl-72">
            <x-admin.topbar :title="$title" />

            <main id="main" class="mx-auto w-full max-w-screen-2xl flex-1 px-4 pb-16 pt-8 sm:px-6 lg:px-12 lg:pt-10">
                <x-admin.flash />
                {{ $slot }}
            </main>
        </div>
    </div>
    @stack('scripts')
</body>
</html>
