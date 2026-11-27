<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'toady' => [
        'owner_email' => env('TOADY_OWNER_EMAIL'),
        // Contact address advertised in the outbound API User-Agent (OSM/Wikimedia etiquette). Optional.
        'contact_email' => env('TOADY_CONTACT_EMAIL'),
        // Counties the owner has split into finer catalog regions of their own: a portal the nightly labelling puts in
        // such a county takes the label of its nearest neighbour among those regions instead. JSON, e.g.
        // {"Glynn County, GA": ["Brunswick / mainland", "Jekyll Island"]}
        'catalog_region_folds' => json_decode((string) env('TOADY_CATALOG_REGION_FOLDS', '{}'), true) ?: [],
    ],

    // Google place search for the AI concierge (Maps Grounding Lite, `search_places`). Dormant until a key is set:
    // the tool is then offered to the model. Server-side key — restrict it to that one API + the server IPs.
    // Results are never cached (Google's terms); daily_cap bounds the bill (10k Essentials calls/month are free).
    'google_maps_grounding' => [
        'key' => env('GOOGLE_MAPS_GROUNDING_KEY'),
        'daily_cap' => (int) env('GOOGLE_MAPS_GROUNDING_DAILY_CAP', 200),
        'per_user_daily_cap' => (int) env('GOOGLE_MAPS_GROUNDING_USER_DAILY_CAP', 25),
    ],

    // Google Analytics (GA4). Loaded for GUESTS only — never on the authenticated app, to honour the
    // "no analytics following you around" promise. Dormant until GOOGLE_ANALYTICS_ID (G-XXXXXXX) is set.
    'google_analytics' => [
        'id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    // Optional "Support toady" donate link (e.g. a Ko-fi URL). Dormant until DONATE_URL is set —
    // the /donate page then shows the button; otherwise it reads "donations open soon".
    'donate' => [
        'url' => env('DONATE_URL'),
    ],

    // Google Search Console HTML-tag ownership verification (alternative to the GA / DNS methods).
    // Dormant until GOOGLE_SITE_VERIFICATION is set to the token from the GSC "HTML tag" method.
    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),

    'vapid' => [
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@toady.net'),
    ],

    // OpenRouteService — walking directions on the op map (foot-walking profile)
    'ors' => [
        'key' => env('ORS_API_KEY'),
    ],

    // TomTom — live traffic-flow tiles, proxied server-side (key never reaches the browser)
    'tomtom' => [
        'key' => env('TOMTOM_KEY'),
    ],

];
