{{-- A simple public page (privacy policy, account deletion): site header, one readable column, footer links. --}}
@props(['s', 'title', 'logoUrl', 'description' => null])
@php $seo = ['title' => $title.' · '.$s['salon_name'], 'description' => $description ?? $title.' for the '.$s['salon_name'].' app.', 'indexing' => false]; @endphp
<x-site.layout :s="$s" :seo="$seo">
    <header class="border-b border-outline-variant/60 bg-surface">
        <div class="mx-auto flex h-16 max-w-3xl items-center gap-3 px-4 sm:px-6">
            <a href="{{ url('/') }}" class="flex items-center gap-3">
                <img src="{{ $logoUrl }}" alt="{{ $s['salon_name'] }} logo" width="40" height="40" class="h-10 w-10 rounded-xl bg-white object-contain p-0.5 shadow-e1">
                <span class="font-semibold">{{ $s['salon_name'] }}</span>
            </a>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $title }}</h1>
        {{ $slot }}
    </main>

    <footer class="border-t border-outline-variant bg-surface-container">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-6 text-sm text-on-surface-variant sm:px-6">
            <a href="{{ url('/') }}" class="hover:underline">Home</a>
            <a href="{{ route('privacy') }}" class="hover:underline">Privacy policy</a>
            <a href="{{ route('account-deletion') }}" class="hover:underline">Delete account</a>
            <span class="ml-auto">© {{ now()->year }} {{ $s['salon_name'] }}</span>
        </div>
    </footer>
</x-site.layout>
