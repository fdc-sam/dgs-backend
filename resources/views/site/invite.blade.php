{{-- A customer's invite link (/i/{code}): opens the DGS app with the code, or the Play Store (which passes it on). --}}
@php
    $title = $inviterName ? "{$inviterName} invited you to {$s['salon_name']}" : "Join {$s['salon_name']}";
    $price = \App\Support\Fmt::peso($cardPrice);
@endphp
<x-site.page :s="$s" :title="$title" :logo-url="$logoUrl" :description="$shareDescription" :share-title="$shareTitle" :share-image="$cardUrl" :canonical="$shareUrl">
    <p class="mt-4 leading-relaxed text-on-surface-variant">
        Book your hair and spa services in the {{ $s['salon_name'] }} app.
        @if ($membershipOffered)
            Become a member for <strong class="text-on-surface">{{ $price }}</strong> and get member prices on your services for a whole year.
        @endif
    </p>

    <section class="mt-8 space-y-3 rounded-2xl border border-outline-variant bg-white p-6">
        <x-admin.button :href="$intentUrl" class="w-full" id="open-app">Open the app</x-admin.button>
        <x-admin.button :href="$playUrl" variant="outlined" class="w-full">Get it on Google Play</x-admin.button>
        @if ($code)
            <p class="pt-2 text-sm text-on-surface-variant">
                Your invite code is <strong class="font-mono text-on-surface">{{ $code }}</strong>. The app fills it in when you sign up.
                If it doesn't, enter it on <strong class="text-on-surface">Profile → Invites</strong> within {{ $claimDays }} days of signing up.
            </p>
        @endif
    </section>

    <p class="mt-6 text-sm text-on-surface-variant">The {{ $s['salon_name'] }} app is available for Android phones.</p>

    <script>
        // On Android, go straight to the app (or the Play Store with the code) instead of waiting for a tap.
        if (/Android/i.test(navigator.userAgent)) {
            setTimeout(function () { window.location.href = document.getElementById('open-app').href; }, 300);
        }
    </script>
</x-site.page>
