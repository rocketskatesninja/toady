<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark{{ optional(auth()->user())->faction === 'RES' ? ' res' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#04070b">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($gsv = config('services.google_site_verification'))<meta name="google-site-verification" content="{{ $gsv }}">@endif
    {{-- Apply saved theme before paint to avoid a flash of the wrong mode. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">try{if(localStorage.getItem('toady-theme')==='daylight'){var h=document.documentElement;h.classList.remove('dark');h.classList.add('daylight');}if(localStorage.getItem('toady-fatfinger')==='1'){document.documentElement.classList.add('fat-finger');}}catch(e){}</script>
    {{-- SEO + social, server-rendered so non-JS crawlers and social scrapers see them --}}
    @php
        $seoTitle = config('app.name', 'toady').' — multiplayer mission command for Ingress';
        $seoDesc = 'Plan the fields, share one join link, and run the whole Ingress op live on a shared scanner with your team — directives, keys, comms, and a live map. Ephemeral by design: close the op and it’s gone. Free, no install.';
        $seoUrl = url()->current();
        $seoImg = url('/og.png');
        $seoLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => 'toady',
            'alternateName' => 'toady.net',
            'url' => 'https://toady.net',
            'description' => $seoDesc,
            'applicationCategory' => 'UtilitiesApplication',
            'operatingSystem' => 'Web, iOS, Android',
            'browserRequirements' => 'Requires JavaScript',
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
        ];
    @endphp
    <title inertia>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDesc }}">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    <meta name="keywords" content="Ingress, mission planner, field planner, fielding, links, keys, IITC, agent coordination, ops, mission command">
    <link rel="canonical" href="{{ $seoUrl }}">
    {{-- Faction-tinted favicon: RES agents get the blue field mark, everyone else the green one. --}}
    @php($favicon = optional(auth()->user())->faction === 'RES' ? '/favicon-res.svg' : '/favicon.svg')
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="{{ $favicon }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ $favicon }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="toady">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDesc }}">
    <meta property="og:url" content="{{ $seoUrl }}">
    <meta property="og:image" content="{{ $seoImg }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $seoTitle }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDesc }}">
    <meta name="twitter:image" content="{{ $seoImg }}">
    <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">@json($seoLd)</script>

    @guest
        @if ($gaId = config('services.google_analytics.id'))
            {{-- Google Analytics (GA4) — guests only; never on the authenticated app --}}
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $gaId }}');</script>
        @endif
    @endguest
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
