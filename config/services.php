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
        'token' => env('POSTMARK_TOKEN'),
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

    'invoice_payment_initiations' => [
        // An interactive object remains resumable during this window. After
        // it elapses, adapters may cancel it only when the provider can prove
        // a terminal, non-chargeable state.
        'abandon_after_minutes' => (int) env(
            'PAYMENT_INITIATION_ABANDON_AFTER_MINUTES',
            120
        ),
        'reconcile_every_seconds' => (int) env(
            'PAYMENT_INITIATION_RECONCILE_EVERY_SECONDS',
            60
        ),
        'lease_seconds' => (int) env(
            'PAYMENT_INITIATION_RECONCILE_LEASE_SECONDS',
            120
        ),
    ],

];
