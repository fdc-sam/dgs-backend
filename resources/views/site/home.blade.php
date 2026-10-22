@php
    use App\Support\Fmt;
    $name = $s['salon_name'];
    $phone = $s['salon_phone'] ?: $branches->first()['model']->phone ?? null;
    $tel = $phone ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;
    $peso = fn ($v) => ($s['currency_symbol'] ?? '₱').number_format((float) $v, (float) $v == floor((float) $v) ? 0 : 2);
    $firstBranch = $branches->first();
    $icon = [
        'phone' => '<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/>',
        'pin' => '<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>',
        'clock' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm.75 10.4 3.5 2.1-.75 1.25-4.25-2.55V7h1.5z"/>',
        'fb' => '<path d="M14 9V7.5c0-.7.15-1 1.1-1H17V3h-2.8C11.3 3 10.5 4.5 10.5 7v2H8v3.5h2.5V21H14v-8.5h2.6L17 9z"/>',
        'star' => '<path d="m12 17.3 6.2 3.7-1.6-7 5.4-4.7-7.2-.6L12 2 9.2 8.7 2 9.3l5.4 4.7-1.6 7z"/>',
        'android' => '<path d="m17.6 9.48 1.84-3.18a.63.63 0 0 0-1.09-.63l-1.88 3.24a11.4 11.4 0 0 0-8.94 0L5.65 5.67a.64.64 0 0 0-1.09.63L6.4 9.48A10.8 10.8 0 0 0 1 18h22a10.8 10.8 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/>',
    ];
    $svg = fn ($k, $cls = 'h-5 w-5') => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'.$icon[$k].'</svg>';
    // Customer app (public/downloads/dgs-salon.apk, copied in before deploy — not in git). Hidden when absent.
    $apkPath = public_path('downloads/dgs-salon.apk');
    $apk = is_file($apkPath) ? [
        'url' => asset('downloads/dgs-salon.apk').'?v='.filemtime($apkPath),
        'size' => max(1, round(filesize($apkPath) / 1048576)),
    ] : null;
@endphp
<x-site.layout :s="$s" :seo="$seo" :json-ld="$jsonLd">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary">Skip to content</a>

    <header class="sticky top-0 z-30 border-b border-outline-variant/60 bg-surface/90 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6">
            <a href="{{ url('/') }}" class="flex items-center gap-3">
                <img src="{{ $logoUrl }}" alt="{{ $name }} logo" width="44" height="44" class="h-11 w-11 rounded-xl bg-white object-contain p-0.5 shadow-e1">
                <span class="hidden font-semibold sm:block">{{ $name }}</span>
            </a>
            <nav class="ml-auto hidden items-center gap-1 text-sm font-medium md:flex" aria-label="Sections">
                @if ($apk)<a href="#app" class="rounded-full px-3 py-2 hover:bg-surface-container-high">Get the app</a>@endif
                @if ($categories->isNotEmpty())<a href="#services" class="rounded-full px-3 py-2 hover:bg-surface-container-high">Services & prices</a>@endif
                @if ($promotions->isNotEmpty())<a href="#promos" class="rounded-full px-3 py-2 hover:bg-surface-container-high">Promos</a>@endif
                @if ($branches->isNotEmpty())<a href="#visit" class="rounded-full px-3 py-2 hover:bg-surface-container-high">Location & hours</a>@endif
                @if ($reviews->isNotEmpty())<a href="#reviews" class="rounded-full px-3 py-2 hover:bg-surface-container-high">Reviews</a>@endif
            </nav>
            @if ($tel)
                <a href="{{ $tel }}" class="ml-auto inline-flex min-h-11 items-center gap-2 rounded-full bg-primary px-4 text-sm font-medium text-on-primary shadow-e1 hover:bg-primary-hover md:ml-2">{!! $svg('phone', 'h-4 w-4') !!} Call</a>
            @endif
        </div>
    </header>

    <main id="main">
        {{-- Hero --}}
        <section class="relative overflow-hidden bg-on-surface text-white">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_10%,#F0B80066,transparent_55%),radial-gradient(circle_at_90%_100%,#7A590088,transparent_50%)]" aria-hidden="true"></div>
            <div class="relative mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.3fr_1fr] md:py-20">
                <div>
                    @if ($seo['place'])
                        <p class="mb-3 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-sm text-gold">{!! $svg('pin', 'h-4 w-4') !!} {{ $seo['place'] }}</p>
                    @endif
                    <h1 class="text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">{{ $name }}</h1>
                    <p class="mt-4 max-w-xl text-lg text-white/80">
                        Hair salon &amp; spa{{ $seo['place'] ? ' in '.$seo['place'] : '' }}@if ($categories->isNotEmpty()): {{ $categories->pluck('name')->take(4)->map(fn ($n) => \Illuminate\Support\Str::lower($n))->join(', ', ' and ') }}@endif.
                    </p>
                    @if ($ratingAverage)
                        <p class="mt-4 flex items-center gap-2 text-sm text-white/80">
                            <span class="flex text-gold">@for ($i = 0; $i < 5; $i++){!! $svg('star', 'h-4 w-4') !!}@endfor</span>
                            {{ number_format($ratingAverage, 1) }} from {{ $ratingCount }} customer {{ \Illuminate\Support\Str::plural('review', $ratingCount) }}
                        </p>
                    @endif
                    <div class="mt-8 flex flex-wrap gap-3">
                        @if ($apk)
                            <a href="{{ $apk['url'] }}" download="DGS-Salon.apk" class="inline-flex min-h-12 items-center gap-2 rounded-full bg-white px-6 font-semibold text-on-surface hover:bg-white/90">{!! $svg('android') !!} Download for Android</a>
                        @endif
                        @if ($tel)
                            <a href="{{ $tel }}" class="inline-flex min-h-12 items-center gap-2 rounded-full bg-gold px-6 font-semibold text-on-primary-container hover:brightness-95">{!! $svg('phone') !!} Call {{ $phone }}</a>
                        @endif
                        @if (filled($s['facebook_url'] ?? null))
                            <a href="{{ $s['facebook_url'] }}" rel="noopener" target="_blank" class="inline-flex min-h-12 items-center gap-2 rounded-full border border-white/40 px-6 font-medium hover:bg-white/10">{!! $svg('fb') !!} Message us on Facebook</a>
                        @endif
                        @if ($firstBranch)
                            <a href="{{ $firstBranch['map_url'] }}" rel="noopener" target="_blank" class="inline-flex min-h-12 items-center gap-2 rounded-full border border-white/40 px-6 font-medium hover:bg-white/10">{!! $svg('pin') !!} Get directions</a>
                        @endif
                    </div>
                </div>
                <div class="mx-auto hidden w-full max-w-xs md:block">
                    <img src="{{ \App\Support\Fmt::versionedAsset('images/dgs-logo-512.png') }}" alt="{{ $name }} logo" width="512" height="512" class="w-full rounded-[2rem] bg-white p-6 shadow-e2">
                </div>
            </div>
        </section>

        {{-- Customer app --}}
        @if ($apk)
            <section id="app" class="scroll-mt-20 border-b border-outline-variant/60">
                <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between">
                    <div class="max-w-2xl">
                        <h2 class="text-2xl font-semibold tracking-tight">Book from your phone</h2>
                        <p class="mt-2 text-on-surface-variant">Get the {{ $name }} app for Android to book appointments and get reminders before your visit.</p>
                        <ol class="mt-4 list-decimal space-y-1 pl-5 text-sm text-on-surface-variant">
                            <li>On your Android phone, tap <strong class="text-on-surface">Download for Android</strong>.</li>
                            <li>Open the downloaded file. If your phone asks, allow your browser to <strong class="text-on-surface">install unknown apps</strong>.</li>
                            <li>Tap <strong class="text-on-surface">Install</strong>, then open the app and sign up.</li>
                        </ol>
                    </div>
                    <div class="flex flex-col items-start gap-2 md:items-center">
                        <a href="{{ $apk['url'] }}" download="DGS-Salon.apk" class="inline-flex min-h-12 items-center gap-2 rounded-full bg-primary px-6 font-semibold text-on-primary shadow-e1 hover:bg-primary-hover">{!! $svg('android') !!} Download for Android</a>
                        <p class="text-xs text-on-surface-variant">Android 8.0 or newer · {{ $apk['size'] }} MB</p>
                    </div>
                </div>
            </section>
        @endif

        {{-- Services & prices --}}
        @if ($categories->isNotEmpty())
            <section id="services" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-14 sm:px-6">
                <h2 class="text-3xl font-semibold tracking-tight">Services &amp; prices</h2>
                <p class="mt-2 text-on-surface-variant">Prices may vary by hair length and branch. Ask us for the exact price for your hair.</p>
                <div class="mt-8 space-y-10">
                    @foreach ($categories as $category)
                        <div>
                            <h3 class="mb-4 text-xl font-semibold">{{ $category->name }}</h3>
                            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($category->services as $service)
                                    @php $from = min((float) $service->regular_price, (float) ($service->variants->min('price') ?? $service->regular_price)); @endphp
                                    <li class="overflow-hidden rounded-card border border-outline-variant/70 bg-white shadow-e1">
                                        @if ($service->image)
                                            <img src="{{ Fmt::url($service->image) }}" alt="{{ $service->name }}" loading="lazy" width="400" height="240" class="aspect-[5/3] w-full object-cover">
                                        @endif
                                        <div class="p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <h4 class="font-semibold">{{ $service->name }}</h4>
                                                <p class="shrink-0 font-semibold text-primary">{{ $service->variants->isNotEmpty() ? 'from ' : '' }}{{ $peso($from) }}</p>
                                            </div>
                                            @if ($service->short_description)<p class="mt-1 text-sm text-on-surface-variant">{{ $service->short_description }}</p>@endif
                                            <p class="mt-2 text-xs text-outline">{{ $service->duration_minutes }} minutes</p>
                                            @if ($service->variants->isNotEmpty())
                                                <ul class="mt-3 space-y-1 border-t border-outline-variant/60 pt-3 text-sm">
                                                    @foreach ($service->variants->sortBy('price') as $variant)
                                                        <li class="flex justify-between"><span>{{ $variant->name }}</span><span class="font-medium">{{ $peso($variant->price) }}</span></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Promotions --}}
        @if ($promotions->isNotEmpty())
            <section id="promos" class="scroll-mt-20 bg-gold-soft/60">
                <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <h2 class="text-3xl font-semibold tracking-tight">Current promos</h2>
                    <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($promotions as $promo)
                            <li class="overflow-hidden rounded-card bg-white shadow-e1">
                                @if ($promo->image)<img src="{{ Fmt::url($promo->image) }}" alt="{{ $promo->name }}" loading="lazy" width="400" height="225" class="aspect-video w-full object-cover">@endif
                                <div class="p-4">
                                    <h3 class="font-semibold">{{ $promo->name }}</h3>
                                    @if ($promo->description)<p class="mt-1 text-sm text-on-surface-variant">{{ $promo->description }}</p>@endif
                                    <p class="mt-2 text-xs text-outline">Valid until {{ $promo->end_at->format('F j, Y') }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        {{-- Achievements --}}
        @if ($achievements->isNotEmpty())
            <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                <h2 class="text-3xl font-semibold tracking-tight">Our achievements</h2>
                <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($achievements as $a)
                        <li class="overflow-hidden rounded-card border border-outline-variant/70 bg-white shadow-e1">
                            <img src="{{ Fmt::url($a->image) }}" alt="{{ $a->title }}" loading="lazy" width="400" height="300" class="aspect-[4/3] w-full object-cover">
                            <div class="p-4"><h3 class="font-semibold">{{ $a->title }}</h3><p class="mt-1 text-sm text-on-surface-variant">{{ \Illuminate\Support\Str::limit($a->description, 140) }}</p></div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Location & hours --}}
        @if ($branches->isNotEmpty())
            <section id="visit" class="scroll-mt-20 bg-surface-container">
                <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
                    <h2 class="text-3xl font-semibold tracking-tight">Visit us{{ $seo['place'] ? ' in '.$seo['place'] : '' }}</h2>
                    <div class="mt-8 grid gap-6 md:grid-cols-2">
                        @foreach ($branches as $branch)
                            @php $b = $branch['model']; @endphp
                            <article class="rounded-card bg-white p-6 shadow-e1">
                                <h3 class="text-xl font-semibold">{{ $b->name }}</h3>
                                @if ($b->address)<p class="mt-3 flex gap-2 text-on-surface-variant">{!! $svg('pin', 'mt-0.5 h-5 w-5 shrink-0 text-primary') !!}<span>{{ $b->address }}</span></p>@endif
                                @if ($b->phone)<p class="mt-2 flex gap-2">{!! $svg('phone', 'mt-0.5 h-5 w-5 shrink-0 text-primary') !!}<a class="hover:underline" href="tel:{{ preg_replace('/[^\d+]/', '', $b->phone) }}">{{ $b->phone }}</a></p>@endif
                                <h4 class="mt-5 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-on-surface-variant">{!! $svg('clock', 'h-4 w-4') !!} Opening hours</h4>
                                <table class="mt-2 w-full text-sm">
                                    <tbody>
                                        @foreach ($branch['hours'] as $h)
                                            <tr @class(['font-semibold text-primary' => $h['today']])>
                                                <th scope="row" class="py-1 text-left font-normal">{{ $h['day'] }}@if ($h['today']) (today)@endif</th>
                                                <td class="py-1 text-right">{{ $h['open'] ? \Carbon\Carbon::parse($h['from'])->format('g:i A').' – '.\Carbon\Carbon::parse($h['to'])->format('g:i A') : 'Closed' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <a href="{{ $branch['map_url'] }}" rel="noopener" target="_blank" class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-full border border-outline px-5 text-sm font-medium text-primary hover:bg-primary/5">{!! $svg('pin', 'h-4 w-4') !!} Open in Google Maps</a>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- Reviews --}}
        @if ($reviews->isNotEmpty())
            <section id="reviews" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-14 sm:px-6">
                <h2 class="text-3xl font-semibold tracking-tight">What our customers say</h2>
                <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($reviews as $review)
                        @php
                            $parts = preg_split('/\s+/', trim((string) $review->customer?->name)) ?: [];
                            $who = ($parts[0] ?? 'Customer').(count($parts) > 1 ? ' '.mb_substr(end($parts), 0, 1).'.' : '');
                        @endphp
                        <li class="rounded-card border border-outline-variant/70 bg-white p-5 shadow-e1">
                            <p class="flex text-gold" aria-label="{{ $review->rating }} out of 5 stars">@for ($i = 0; $i < 5; $i++)<span @class(['text-outline-variant' => $i >= $review->rating])>{!! $svg('star', 'h-4 w-4') !!}</span>@endfor</p>
                            @if ($review->review)<p class="mt-3 text-sm">“{{ $review->review }}”</p>@endif
                            <p class="mt-3 text-xs text-on-surface-variant">{{ $who }}@if ($review->service) · {{ $review->service->name }}@endif</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>

    <footer class="border-t border-outline-variant bg-surface-container">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-8 text-sm text-on-surface-variant sm:px-6">
            <div>
                <p class="font-semibold text-on-surface">{{ $name }}</p>
                @if ($s['salon_address'] || $firstBranch)<p>{{ $s['salon_address'] ?: $firstBranch['model']->address }}</p>@endif
                @if ($phone)<p><a href="{{ $tel }}" class="hover:underline">{{ $phone }}</a></p>@endif
            </div>
            <div class="flex items-center gap-4">
                @if (filled($s['facebook_url'] ?? null))<a href="{{ $s['facebook_url'] }}" rel="noopener" target="_blank" class="hover:underline">Facebook</a>@endif
                <a href="{{ route('privacy') }}" class="hover:underline">Privacy policy</a>
                <a href="{{ route('account-deletion') }}" class="hover:underline" rel="nofollow">Delete account</a>
                <a href="{{ route('login') }}" class="hover:underline" rel="nofollow">Staff login</a>
                <span>© {{ now()->year }}</span>
            </div>
        </div>
    </footer>
</x-site.layout>
