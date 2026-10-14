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

    
'msg91' => [
    'auth_key' => env('MSG91_AUTH_KEY'),
],

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

    'payu' => [
        'key' => env('PAYU_KEY'),
        'salt' => env('PAYU_SALT'),
        'base_url' => env('PAYU_BASE_URL', 'https://test.payu.in/_payment'),
        'verify_url' => env(
            'PAYU_VERIFY_URL',
            'https://test.payu.in/merchant/postservice?form=2'
        ),
        'mode' => env('PAYU_MODE', 'sandbox'),
        'surl' => env('PAYU_SURL'),
        'furl' => env('PAYU_FURL'),
        // Page the customer's browser lands on after paying (app closes its WebView here).
        'result_url' => env('PAYU_RESULT_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
