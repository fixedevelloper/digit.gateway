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
    'digitwave' => [
        'url' => env('DIGITWAVE_BASE_URL', 'https://digitwave-services.com/api/'),
        'api_key' => env('DIGITWAVE_API_KEY'),
        // Secret HMAC (préfixe 'agswhsec_') fourni par le dashboard Digitwave, utilisé
        // pour vérifier l'en-tête X-Webhook-Signature des notifications entrantes.
        'webhook_secret' => env('DIGITWAVE_WEBHOOK_SECRET'),
    ],

    /*
     * Sous-domaine dédié à la doc API marchande (/docs/api), ex: docs.digitgateway.com.
     * Laissé vide en local : la doc reste servie sur le même domaine que l'API,
     * à /docs/api. Voir App\Providers\AppServiceProvider::boot().
     */
    'docs' => [
        'domain' => env('DOCS_DOMAIN'),
    ],
];
