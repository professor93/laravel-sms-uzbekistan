<?php

declare(strict_types=1);

return [

    'default' => env('SMS_PROVIDER', 'eskiz'),

    /*
     | Driver alias map: alias => FQCN of a class extending
     | Uzbek\Sms\Drivers\AbstractDriver. Merged over the built-ins
     | (eskiz, playmobile, textup, sayqal); reusing a built-in alias
     | overrides it. A provider's `driver` key may also reference a
     | driver class directly by FQCN, without registering an alias here.
     |
     | 'drivers' => ['vendor' => \App\Sms\VendorDriver::class],
     */
    'drivers' => [],

    /*
     | Dynamic prefix rules: FQCN implementing Uzbek\Sms\Contracts\PrefixRules,
     | resolved from the container once per send call. Its allowlist()/
     | blocklist() lists are merged with each provider's static `prefixes`
     | config (blocked always wins; empty allowlist = no restriction). Set
     | here for all providers, or per provider with a `prefix_rules` key
     | (which takes precedence). If the source fails (e.g. the database is
     | down) a warning is logged and only the static lists apply — keep hard
     | legal blocks in the config, use the class for runtime-managed lists.
     */
    'prefix_rules' => null,

    'silent' => (bool) env('SMS_SILENT', false),

    /*
     | Fake mode: sends succeed (or fail, see success_rate) without any HTTP
     | call leaving the machine — no auth, no provider traffic. Intended for
     | local development and staging. success_rate is a probability from 0
     | to 1: 1 (default) = every send succeeds, 0.7 = ~70% succeed, 0 = every
     | send fails with a simulated error. A blank or invalid value falls back
     | to 1 (a warning is logged for invalid values unless sms.silent is on).
     */
    'fake' => [
        'enabled' => (bool) env('SMS_FAKE', false),
        'success_rate' => env('SMS_FAKE_SUCCESS_RATE', 1.0),
    ],

    /*
     | Incoming delivery reports are posted to {path}/{provider}. Per provider,
     | an optional `webhook_handler` (FQCN implementing
     | Uzbek\Sms\Contracts\WebhookHandler) is called with the parsed reports;
     | without one the webhook is written to the log. A handler also unlocks
     | the endpoint for drivers that cannot parse webhooks themselves — the
     | handler then owns request verification.
     */
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

            // Default fallback provider: when a send fails, it is retried through
            // this provider. The value must be another key in this `providers` array.
            // Leave empty (null) to disable. Override per-message with
            // ->useFallback('other') or disable with ->withoutFallback().
            'fallback' => env('ESKIZ_FALLBACK'),

            'base_url' => env('ESKIZ_BASE_URL', 'https://notify.eskiz.uz/api'),
            'email' => env('ESKIZ_EMAIL'),
            'password' => env('ESKIZ_PASSWORD'),
            'from' => env('ESKIZ_FROM', '4546'),
            'token_ttl' => (int) env('ESKIZ_TOKEN_TTL', 2592000),

            // Delivery callbacks: nothing is sent while callback_enabled is
            // false. When true, each send carries callback_url — the explicit
            // ESKIZ_CALLBACK_URL, or (when it is null and sms.webhook is
            // enabled) the package webhook URL, with ?token= appended when a
            // webhook_secret is set. A null resolved URL is omitted entirely.
            'callback_enabled' => (bool) env('ESKIZ_CALLBACK_ENABLED', false),
            'callback_url' => env('ESKIZ_CALLBACK_URL'),

            // Webhook security for incoming Eskiz delivery reports; each
            // check is enforced only when configured.
            'webhook_secret' => env('ESKIZ_WEBHOOK_SECRET'),
            'allowed_ips' => [],

            // Optional app-side processor for incoming delivery reports:
            // 'webhook_handler' => \App\Sms\EskizWebhookHandler::class,
            'prefixes' => [
                'allowed' => array_filter(explode(',', (string) env('ESKIZ_ALLOWED_PREFIXES', ''))),
                'blocked' => array_filter(explode(',', (string) env('ESKIZ_BLOCKED_PREFIXES', ''))),
            ],
            'http_options' => [],
        ],

        'playmobile' => [
            'driver' => 'playmobile',
            'enabled' => (bool) env('PLAYMOBILE_ENABLED', true),
            'fallback' => env('PLAYMOBILE_FALLBACK'),
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
            'fallback' => env('TEXTUP_FALLBACK'),
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
            'fallback' => env('SAYQAL_FALLBACK'),
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
