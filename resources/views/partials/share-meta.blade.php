{{-- Link previews (Messenger, Facebook, Viber, WhatsApp, Slack…) read these Open Graph tags. --}}
@php $shareDescription = $shareDescription ?? 'Book your salon appointments online with '.$salonName.'.'; @endphp
<meta name="description" content="{{ $shareDescription }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $salonName }}">
<meta property="og:title" content="{{ $shareTitle ?? $salonName }}">
<meta property="og:description" content="{{ $shareDescription }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ \App\Support\Fmt::versionedAsset('images/og-share.png') }}">
<meta property="og:image:secure_url" content="{{ \App\Support\Fmt::versionedAsset('images/og-share.png') }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $salonName }} logo">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $shareTitle ?? $salonName }}">
<meta name="twitter:description" content="{{ $shareDescription }}">
<meta name="twitter:image" content="{{ \App\Support\Fmt::versionedAsset('images/og-share.png') }}">
<link rel="apple-touch-icon" href="{{ \App\Support\Fmt::versionedAsset('images/dgs-logo-256.png') }}">
