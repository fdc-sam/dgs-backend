{{-- Link previews (Messenger, Facebook, Viber, WhatsApp, Slack…) read these Open Graph tags. --}}
@php
    $shareDescription = $shareDescription ?? 'Book your salon appointments online with '.$salonName.'.';
    // A page can bring its own 1200×630 picture (an invite link shows the invite card).
    $shareImageUrl = $shareImage ?? null;
    $shareImageAlt = $shareImageUrl ? $shareTitle : $salonName.' logo';
    $shareImageType = $shareImageUrl && str_contains(parse_url($shareImageUrl, PHP_URL_PATH) ?? '', '.jp') ? 'image/jpeg' : 'image/png';
    $shareImageUrl ??= \App\Support\Fmt::versionedAsset('images/og-share.png');
@endphp
<meta name="description" content="{{ $shareDescription }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $salonName }}">
<meta property="og:title" content="{{ $shareTitle ?? $salonName }}">
<meta property="og:description" content="{{ $shareDescription }}">
<meta property="og:url" content="{{ $shareUrl ?? url()->current() }}">
<meta property="og:image" content="{{ $shareImageUrl }}">
<meta property="og:image:secure_url" content="{{ $shareImageUrl }}">
<meta property="og:image:type" content="{{ $shareImageType }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $shareImageAlt }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $shareTitle ?? $salonName }}">
<meta name="twitter:description" content="{{ $shareDescription }}">
<meta name="twitter:image" content="{{ $shareImageUrl }}">
<link rel="apple-touch-icon" href="{{ \App\Support\Fmt::versionedAsset('images/dgs-logo-256.png') }}">
