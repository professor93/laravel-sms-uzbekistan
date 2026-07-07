<?php

declare(strict_types=1);

return [

    'default' => env('SMS_PROVIDER', 'eskiz'),

    'silent' => (bool) env('SMS_SILENT', false),

    'webhook' => [
        'enabled' => (bool) env('SMS_WEBHOOK_ENABLED', false),
        'path' => env('SMS_WEBHOOK_PATH', 'sms/webhooks'),
        'middleware' => [],
    ],

    'logging' => [
        'database' => (bool) env('SMS_LOG_DATABASE', false),
        'debug' => (bool) env('SMS_LOG_DEBUG', false),
        'channel' => env('SMS_LOG_CHANNEL'),
    ],

    'cache' => [
        'store' => env('SMS_CACHE_STORE'),
        'prefix' => env('SMS_CACHE_PREFIX', 'sms'),
    ],

    'providers' => [

        'eskiz' => [
            'driver' => 'eskiz',
            'enabled' => (bool) env('ESKIZ_ENABLED', true),
            'base_url' => env('ESKIZ_BASE_URL', 'https://notify.eskiz.uz/api'),
            'email' => env('ESKIZ_EMAIL'),
            'password' => env('ESKIZ_PASSWORD'),
            'from' => env('ESKIZ_FROM', '4546'),
            'token_ttl' => (int) env('ESKIZ_TOKEN_TTL', 2592000),
            // Reserved: Eskiz push callbacks are out of scope for v1.
            'callback_url' => env('ESKIZ_CALLBACK_URL'),
            'prefixes' => [
                'allowed' => array_filter(explode(',', (string) env('ESKIZ_ALLOWED_PREFIXES', ''))),
                'blocked' => array_filter(explode(',', (string) env('ESKIZ_BLOCKED_PREFIXES', ''))),
            ],
            'http_options' => [],
        ],

        'playmobile' => [
            'driver' => 'playmobile',
            'enabled' => (bool) env('PLAYMOBILE_ENABLED', true),
            'base_url' => env('PLAYMOBILE_BASE_URL', 'https://send.smsxabar.uz/broker-api'),
            'username' => env('PLAYMOBILE_USERNAME'),
            'password' => env('PLAYMOBILE_PASSWORD'),
            'from' => env('PLAYMOBILE_FROM', '3700'),
            'webhook_secret' => env('PLAYMOBILE_WEBHOOK_SECRET'),
            'allowed_ips' => [],
            'prefixes' => [
                'allowed' => array_filter(explode(',', (string) env('PLAYMOBILE_ALLOWED_PREFIXES', ''))),
                'blocked' => array_filter(explode(',', (string) env('PLAYMOBILE_BLOCKED_PREFIXES', ''))),
            ],
            'http_options' => [],
        ],

        'textup' => [
            'driver' => 'textup',
            'enabled' => (bool) env('TEXTUP_ENABLED', true),
            'base_url' => env('TEXTUP_BASE_URL', 'https://sms-api.textup.uz/v1'),
            'auth_url' => env('TEXTUP_AUTH_URL', 'https://api-auth.textup.uz/v1'),
            'email' => env('TEXTUP_EMAIL'),
            'password' => env('TEXTUP_PASSWORD'),
            'from' => env('TEXTUP_NICKNAME_ID'),
            'token_ttl' => (int) env('TEXTUP_TOKEN_TTL', 86400),
            'is_otp' => (bool) env('TEXTUP_IS_OTP', false),
            'prefixes' => [
                'allowed' => array_filter(explode(',', (string) env('TEXTUP_ALLOWED_PREFIXES', ''))),
                'blocked' => array_filter(explode(',', (string) env('TEXTUP_BLOCKED_PREFIXES', ''))),
            ],
            'http_options' => [],
        ],

        'sayqal' => [
            'driver' => 'sayqal',
            'enabled' => (bool) env('SAYQAL_ENABLED', true),
            'base_url' => env('SAYQAL_BASE_URL', 'https://routee.sayqal.uz/sms'),
            'username' => env('SAYQAL_USERNAME'),
            'secret_key' => env('SAYQAL_SECRET_KEY'),
            'service_id' => (int) env('SAYQAL_SERVICE_ID', 1),
            'from' => env('SAYQAL_NICKNAME'),
            'prefixes' => [
                'allowed' => array_filter(explode(',', (string) env('SAYQAL_ALLOWED_PREFIXES', ''))),
                'blocked' => array_filter(explode(',', (string) env('SAYQAL_BLOCKED_PREFIXES', ''))),
            ],
            'http_options' => [],
        ],

    ],

];
