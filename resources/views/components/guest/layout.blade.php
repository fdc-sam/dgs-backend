@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ $salonName }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @include('partials.share-meta')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400..500,0..1,0&display=block">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-container">
    <div class="grid min-h-screen lg:grid-cols-2">
        <div class="relative hidden overflow-hidden bg-on-surface lg:block" aria-hidden="true">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,#F0B80055,transparent_55%),radial-gradient(circle_at_80%_90%,#7A590066,transparent_50%)]"></div>
            <div class="relative flex h-full flex-col justify-between p-12 text-white">
                <img src="{{ $logoUrl }}" alt="" class="h-20 w-20 rounded-2xl bg-white object-contain p-1">
                <div>
                    <p class="text-4xl font-semibold leading-tight">Run the salon<br><span class="text-gold">beautifully.</span></p>
                    <p class="mt-4 max-w-md text-white/70">Bookings, schedules, services and promotions for {{ $salonName }} — all in one place.</p>
                </div>
            </div>
        </div>
        <main class="flex items-center justify-center px-4 py-12 sm:px-8">
            <div class="w-full max-w-md">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <img src="{{ $logoUrl }}" alt="" class="h-14 w-14 rounded-2xl bg-white object-contain p-1 shadow-e1">
                    <p class="text-lg font-semibold">{{ $salonName }}</p>
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
