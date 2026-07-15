<?php

declare(strict_types=1);

return [

    'default' => env('SMS_PROVIDER', 'eskiz'),

    /*
     | Extra driver aliases (alias => AbstractDriver subclass). Merged over
     | the built-ins (eskiz, playmobile, textup, sayqal) — the same alias
     | overrides. A provider's `driver` key also accepts an FQCN directly.
     */
    'drivers' => [],

    /*
     | Dynamic allow/block lists: FQCN implementing Contracts\PrefixRules,
     | resolved once per send call and merged with each provider's static
     | `prefixes` (blocked wins). A per-provider `prefix_rules` key overrides
     | this one. Source failure = warning + static lists only, so keep hard
     | legal blocks in the static config.
     */
    'prefix_rules' => null,

    // Suppresses the package's warning logs.
    'silent' => (bool) env('SMS_SILENT', false),

    /*
     | Fake mode: sends short-circuit before auth — no HTTP leaves the
     | machine. success_rate 0..1 (1 = every send succeeds); blank or
     | invalid falls back to 1 with a warning.
     */
    'fake' => [
        'enabled' => (bool) env('SMS_FAKE', false),
        'success_rate' => env('SMS_FAKE_SUCCESS_RATE', 1.0),
    ],

    /*
     | Delivery reports arrive at POST {path}/{provider}. An optional
     | per-provider `webhook_handler` (Contracts\WebhookHandler) receives the
     | parsed reports and also unlocks the endpoint for drivers that cannot
     | parse webhooks — the handler owns security then. Without a handler
     | the webhook is written to the log.
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
        // Retention for `sms:prune` (schedule it yourself); null = keep forever.
        'prune_after_days' => env('SMS_LOG_PRUNE_DAYS'),
    ],

    'cache' => [
        'store' => env('SMS_CACHE_STORE'),
        'prefix' => env('SMS_CACHE_PREFIX', 'sms'),
    ],

    /*
     | Runtime overrides on top of this file: FQCN implementing
     | Contracts\ProviderConfigOverrides, returning ONLY the changed keys per
     | provider. Shipped DB source: Config\DatabaseProviderConfigOverrides
     | (publish the sms-overrides-migration tag), cached config_overrides_ttl
     | seconds. Source failure = warning + file config.
     */
    'config_overrides' => null,
    'config_overrides_ttl' => 60,

    /*
     | Named templates (:x = wildcard when matching). enforce=false enables
     | only ->template('name', [...]) rendering; enforce=true additionally
     | throws TemplateViolationException for builder text that matches no
     | template — before any transport.
     */
    'templates' => [
        'enforce' => (bool) env('SMS_TEMPLATES_ENFORCE', false),
        'list' => [
            // 'welcome' => 'Xush kelibsiz, :name!',
        ],
    ],

    /*
     | Sms::otp() codes: hashed at rest in the cache, single-use,
     | attempt-limited, resend-throttled. template = raw string, a
     | templates.list name, or a translation key; :code is filled in.
     */
    'otp' => [
        'length' => (int) env('SMS_OTP_LENGTH', 6),
        'ttl' => (int) env('SMS_OTP_TTL', 300),
        'max_attempts' => (int) env('SMS_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown' => (int) env('SMS_OTP_RESEND_COOLDOWN', 60),
        'template' => env('SMS_OTP_TEMPLATE', 'Tasdiqlash kodi: :code'),
    ],

    /*
     | Every provider block also accepts these optional keys (all off unless set):
     |
     | 'fallback' => 'other-provider'                    retry failed sends through another entry
     | 'retry' => ['times' => 3, 'sleep' => 200]         transient 5xx/timeout retries
     | 'circuit_breaker' => ['threshold' => 5, 'cooldown' => 60]
     | 'bulk' => ['chunk' => 500, 'per_second' => 100]   split + pace sendMany()
     | 'price_per_segment' => 115.0                      enables cost() and the log cost column
     | 'prefix_rules' => FQCN                            per-provider dynamic prefix source
     | 'health_check' => FQCN                            per-provider Sms::health() probe
     | 'webhook_handler' => FQCN                         app-side webhook processor
     | 'webhook_secret' / 'allowed_ips'                  incoming webhook guards
     | 'http_options' => []                              raw Guzzle options (proxy, timeout, ...)
     */
    'providers' => [

        'eskiz' => [
            'driver' => 'eskiz',
            'enabled' => (bool) env('ESKIZ_ENABLED', true),
            'fallback' => env('ESKIZ_FALLBACK'),
            'base_url' => env('ESKIZ_BASE_URL', 'https://notify.eskiz.uz/api'),
            'email' => env('ESKIZ_EMAIL'),
            'password' => env('ESKIZ_PASSWORD'),
            'from' => env('ESKIZ_FROM', '4546'),
            'token_ttl' => (int) env('ESKIZ_TOKEN_TTL', 2592000),

            // Fires LowBalanceDetected when balance() drops below this (UZS).
            'low_balance_threshold' => env('ESKIZ_LOW_BALANCE_THRESHOLD'),

            // callback_enabled=true attaches callback_url to every send: the
            // explicit ESKIZ_CALLBACK_URL, or the package webhook URL (with
            // ?token= when webhook_secret is set). Null resolved = omitted.
            'callback_enabled' => (bool) env('ESKIZ_CALLBACK_ENABLED', false),
            'callback_url' => env('ESKIZ_CALLBACK_URL'),

            // Incoming webhook guards; each enforced only when set.
            'webhook_secret' => env('ESKIZ_WEBHOOK_SECRET'),
            'allowed_ips' => [],

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
