<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GST on Advertisements
    |--------------------------------------------------------------------------
    |
    | Advertising is a service, so 18% GST is added on top of the package
    | price. Package prices themselves live in restaurant_ad_package_durations.
    |
    */

    'gst_percentage' => 18.00,

    'currency' => 'INR',

    /*
    |--------------------------------------------------------------------------
    | Advertisement Image Rules
    |--------------------------------------------------------------------------
    |
    | Images are stored on the public disk under restaurant_ads/ and never
    | replace the restaurant or food item images.
    |
    | aspect_ratio: width / height the image must match (within the
    | tolerance), or null to accept any ratio.
    |
    */

    'image' => [
        'directory' => 'restaurant_ads',
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_kb' => 2048,
        'min_width' => 600,
        'min_height' => 300,
        'aspect_ratio_tolerance' => 0.05,
        'aspect_ratio' => [
            'home' => null,
            'food_item' => null,
            'checkout' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway
    |--------------------------------------------------------------------------
    |
    | payu: live/test PayU keys from config/services.php
    | fake: local development only, refused when APP_ENV=production
    |
    */

    'payment_driver' => env('AD_PAYMENT_DRIVER', 'payu'),

    /*
    |--------------------------------------------------------------------------
    | Online Payment On / Off
    |--------------------------------------------------------------------------
    |
    | false: "Pay Now" makes the ad live straight away with payment_status
    |        = pending (pay later, like COD orders). Admin records the payment
    |        afterwards with markPaidByAdmin().
    | true:  ad goes live only after the gateway payment is verified.
    |
    */

    'payment_enabled' => (bool) env('AD_PAYMENT_ENABLED', true),

];
