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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'toady' => [
        'owner_email' => env('TOADY_OWNER_EMAIL'),
        // Contact address advertised in the outbound API User-Agent (OSM/Wikimedia etiquette). Optional.
        'contact_email' => env('TOADY_CONTACT_EMAIL'),
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
