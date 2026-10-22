@props(['s', 'seo', 'jsonLd' => null])
@php $salonName = $s['salon_name']; $shareTitle = $seo['title']; $shareDescription = $seo['description']; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="robots" content="{{ $seo['indexing'] ? 'index, follow, max-image-preview:large' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ url('/') }}">
    @if (filled($s['google_site_verification'] ?? null))
        <meta name="google-site-verification" content="{{ $s['google_site_verification'] }}">
    @endif
    <link rel="icon" type="image/png" href="{{ \App\Support\Fmt::versionedAsset('favicon.png') }}">
    @include('partials.share-meta')
    <meta name="theme-color" content="#F0B800">
    @if ($jsonLd)
        <script type="application/ld+json">@json($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)</script>
    @endif
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface text-on-surface">
    {{ $slot }}
</body>
</html>
