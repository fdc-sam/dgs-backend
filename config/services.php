<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Firebase Cloud Messaging HTTP v1 (service account). Push is disabled when any value is empty.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => str_replace('\\n', "\n", (string) env('FCM_PRIVATE_KEY', '')),
    ],

    // Who sends push notifications: "supabase" (Edge Function triggered by new notification rows)
    // or "fcm" (Laravel calls FCM directly — used for local MySQL development).
    'push' => [
        'driver' => env('PUSH_DRIVER', 'fcm'),
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
        'secret_key' => env('SUPABASE_SECRET_KEY'),
        // Legacy JWT secret (HS256) — signs the short-lived tokens the app uses for Realtime.
        'jwt_secret' => env('SUPABASE_JWT_SECRET'),
        'realtime_token_ttl' => (int) env('SUPABASE_REALTIME_TOKEN_TTL', 3600),
        // Shared secret between the database trigger and the send-push Edge Function.
        'push_webhook_secret' => env('SUPABASE_PUSH_WEBHOOK_SECRET'),
    ],

];
