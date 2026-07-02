# Laravel SMS Uzbekistan

Unified SMS sending for Uzbek providers behind a single contract. Your application code talks to `Uzbek\Sms\Contracts\Driver`; which provider actually delivers the message is a config decision.

| Driver | Auth | Native bulk | Delivery status |
|---|---|---|---|
| `eskiz` | login → cached JWT | yes (`send-batch`) | pull (`checkStatus`) |
| `playmobile` | HTTP Basic | yes (`messages` array) | push (webhook) |
| `textup` | login → cached JWT (separate auth host) | same-text only | pull (`checkStatus`) |
| `sayqal` | per-request signed token | no (loops) | pull (`checkStatus`) |

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require uzbek/laravel-sms
```

The service provider is auto-discovered. Publish the config, then run the migrations (the `sms_logs` table is used by the optional database log):

```bash
php artisan vendor:publish --tag=sms-config
php artisan vendor:publish --tag=sms-migrations
php artisan migrate
```

## Configuration

Pick a default driver and configure the providers you use. Keep the published `config/sms.php` complete — Laravel merges package config at the top level only, so a hand-written partial file would drop the `webhook`/`logging`/`cache` sections entirely.

Full `.env` reference:

```dotenv
# Core
SMS_DRIVER=eskiz                 # default driver: eskiz | playmobile | textup | sayqal
SMS_WEBHOOK_ENABLED=false        # set true to receive delivery callbacks
SMS_WEBHOOK_PATH=sms/webhooks    # webhook base path
SMS_LOG_DATABASE=false           # set true to log every send to sms_logs
SMS_LOG_DEBUG=false              # write structured entries to the Laravel log
SMS_LOG_CHANNEL=                 # Monolog channel for the debug log (empty = default)
SMS_CACHE_STORE=                 # cache store for auth tokens (empty = app default)
SMS_CACHE_PREFIX=sms             # prefix for all package cache keys

# Eskiz — https://notify.eskiz.uz
ESKIZ_ENABLED=true
ESKIZ_EMAIL=account@example.com
ESKIZ_PASSWORD=secret
ESKIZ_FROM=4546
ESKIZ_TOKEN_TTL=2592000          # seconds; Eskiz JWTs live ~30 days
ESKIZ_ALLOWED_PREFIXES=          # comma-separated, e.g. 99890,99891 (empty = all numbers)
ESKIZ_BLOCKED_PREFIXES=          # comma-separated; wins over the allowed list

# PlayMobile / smsxabar
PLAYMOBILE_ENABLED=true
PLAYMOBILE_USERNAME=login
PLAYMOBILE_PASSWORD=secret
PLAYMOBILE_FROM=3700
PLAYMOBILE_WEBHOOK_SECRET=       # random string; part of the callback URL

# TextUp — https://textup.uz
TEXTUP_ENABLED=true
TEXTUP_EMAIL=account@example.com
TEXTUP_PASSWORD=secret
TEXTUP_USER_ID=                  # account owner UUID from the login response
TEXTUP_TEMPLATE_ID=              # UUID of an approved template; message must match it
TEXTUP_NICKNAME_ID=              # optional; empty = the short number
TEXTUP_TOKEN_TTL=86400
TEXTUP_IS_OTP=false

# Sayqal — https://sayqal.uz
SAYQAL_ENABLED=true
SAYQAL_USERNAME=login
SAYQAL_SECRET_KEY=secret
SAYQAL_SERVICE_ID=1
SAYQAL_NICKNAME=
```

Every provider supports the same `*_ALLOWED_PREFIXES` / `*_BLOCKED_PREFIXES` pair (`PLAYMOBILE_...`, `TEXTUP_...`, `SAYQAL_...`) — see [Restricting recipients by prefix](#restricting-recipients-by-prefix).

> **TextUp requires an approved template.** Every `/send` must reference `TEXTUP_TEMPLATE_ID` (a template UUID from your TextUp cabinet), and the message text must match that template. Sends without a matching approved template are rejected by TextUp before delivery. Create/approve the template in the cabinet, then copy its id here.

Each driver block in `config/sms.php` also accepts `http_options` — raw Guzzle options passed to every request. Uzbek providers usually require a whitelisted static IP, so a proxy is a first-class concern:

```php
'eskiz' => [
    // ...
    'http_options' => [
        'proxy' => 'http://10.0.5.1:3128',
        'timeout' => 10,
    ],
],
```

## Sending

### Fluent

```php
use Uzbek\Sms\Contracts\Driver;

$message = app(Driver::class)
    ->to('+998901234567')
    ->text('Your code is 4821')
    ->send();
```

### Direct

```php
$message = app(Driver::class)->send('+998901234567', 'Your code is 4821');
```

### The `sms()` helper

```php
sms()->send('+998901234567', 'Your code is 4821');          // default driver
sms('playmobile')->to('+998901234567')->text('Salom')->send(); // named driver
```

The helper is a thin wrapper over the factory — `sms()` is `DriverFactory::default()`, `sms('eskiz')` is `DriverFactory::make('eskiz')`, with the same fail-fast exceptions for unknown or disabled drivers.

### Dependency injection

The `Driver` contract is bound to the configured default, so constructor injection works anywhere:

```php
use Uzbek\Sms\Contracts\Driver;

final class OrderNotifier
{
    public function __construct(private readonly Driver $sms) {}

    public function notifyShipped(Order $order): void
    {
        $this->sms->send($order->phone, "Order {$order->number} shipped.");
    }
}
```

Phone formats are forgiving — each driver normalizes numbers to its provider's expected format (`+998 90 123-45-67`, `998901234567` and `90 123 45 67` variants all work).

### Facades

Every provider has its own facade, plus `Sms` for the default driver. Each one proxies the exact same singleton the factory returns, so enabled flags, prefix rules, events and logging all apply:

```php
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Facades\EskizSms;
use Uzbek\Sms\Facades\PlayMobileSms;
use Uzbek\Sms\Facades\TextUpSms;
use Uzbek\Sms\Facades\SayqalSms;

Sms::send('+998901234567', 'Your code is 4821');          // the default driver
EskizSms::to('+998901234567')->text('Salom')->send();     // a specific provider
SayqalSms::checkStatus($message->providerMessageId);      // capability methods too
```

Root aliases are auto-registered, so `\EskizSms::send(...)` works without an import. A facade whose driver is disabled throws `DriverDisabledException` on its first call — same fail-fast rule as the factory.

### Bulk: one text, many numbers

```php
use Uzbek\Sms\Data\OutboundMessage;

$results = app(Driver::class)->sendMany(
    OutboundMessage::sameText(['+998901111111', '+998902222222'], 'We are open today until 20:00')
);
```

### Bulk: a different text per number

```php
use Uzbek\Sms\Data\OutboundMessage;

$results = app(Driver::class)->sendMany([
    new OutboundMessage('+998901111111', 'Your table is booked for 19:00'),
    new OutboundMessage('+998902222222', 'Your table is booked for 20:30'),
]);
```

Drivers with a native bulk endpoint (Eskiz, PlayMobile) send the whole list in one HTTP request. TextUp batches when every text is identical and falls back to per-message requests otherwise. Sayqal has no bulk endpoint and always loops. Your code does not change either way.

### Reading results

`send()` returns a `SentMessage`; `sendMany()` returns a `Collection` of them. **The send pipeline never throws** — transport failures come back as failed results, so a partial bulk send reports per-recipient outcomes instead of dying halfway:

```php
$results = app(Driver::class)->sendMany($messages);

foreach ($results as $message) {
    if ($message->successful) {
        echo "{$message->phone} → {$message->providerMessageId} ({$message->status->value})\n";
    } else {
        report(new RuntimeException("SMS to {$message->phone} failed: {$message->errorMessage}"));
    }
}

$results->where('successful', false)->count(); // failed recipients
```

`SentMessage` fields: `driver`, `phone`, `text`, `status` (`DeliveryStatus` enum), `successful`, `providerMessageId`, `errorMessage`, `raw` (the provider response, for debugging).

The only exceptions you will ever see are configuration errors at resolution time — see below.

## Capability detection

Not every provider supports every feature. Detect capabilities with `instanceof` instead of assuming:

```php
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Contracts\HandlesWebhooks;

$driver = app(Driver::class);

// Status pull — Eskiz, TextUp, Sayqal
if ($driver instanceof ChecksDeliveryStatus) {
    $status = $driver->checkStatus($message->providerMessageId);

    if ($status->isFinal()) {
        // delivered / undelivered / failed — stop polling
    }
}

// Status push — PlayMobile; the package handles the webhook for you,
// this check only matters if you build provider-specific UI
if ($driver instanceof HandlesWebhooks) {
    // callbacks arrive at the webhook endpoint automatically
}
```

## Switching and combining drivers

The default driver comes from `SMS_DRIVER`. To use a specific provider regardless of the default, resolve it by name:

```php
use Uzbek\Sms\DriverFactory;

$factory = app(DriverFactory::class);

$factory->make('eskiz')->send($phone, $text);      // marketing route
$factory->make('playmobile')->send($phone, $otp);  // transactional route
```

Or reach for the per-driver facades — `EskizSms::send($phone, $text)` is the same instance behind a static face.

## Enabling and disabling providers

Every driver block has an `enabled` flag (`ESKIZ_ENABLED`, `PLAYMOBILE_ENABLED`, ...). Configuration problems fail fast at resolution time — before any HTTP request:

```php
$factory->make('nexmo');
// Uzbek\Sms\Exceptions\UnknownDriverException:
// SMS driver [nexmo] is not defined. Register it in the DriverFactory map and add a config/sms.php block.

// with ESKIZ_ENABLED=false:
$factory->make('eskiz');
// Uzbek\Sms\Exceptions\DriverDisabledException:
// SMS driver [eskiz] is disabled. Enable it via ESKIZ_ENABLED or config/sms.php.
```

Both extend `Uzbek\Sms\Exceptions\SmsException`. A disabled *default* driver throws on the first `app(Driver::class)` resolution, and webhook requests addressed to a disabled or unknown driver return 404 without leaking why.

## Restricting recipients by prefix

Each driver block accepts a `prefixes` section — an allowed list, a blocked list, or both. By default both are empty and every number goes through.

```php
// config/sms.php
'eskiz' => [
    // ...
    'prefixes' => [
        'allowed' => [],            // non-empty = only these prefixes may receive SMS
        'blocked' => ['99833'],     // always rejected; wins over the allowed list
    ],
],
```

Rules are **per provider** — blocking a prefix on `eskiz` says nothing about `playmobile`. Matching is digit-based, so `+998 33`, `99833` and `998-33` all mean the same prefix, whatever format the number arrives in.

A prohibited number is rejected *before* any HTTP request and follows the usual pipeline rules: you get a `SentMessage::failed(...)` carrying the `ProhibitedPhoneException` message, `SmsSent` still fires, the attempt is logged, and the rest of a bulk send continues untouched:

```php
config(['sms.drivers.eskiz.prefixes.blocked' => ['99897']]);

$results = app(Driver::class)->sendMany(OutboundMessage::sameText(
    ['+998901111111', '+998971111111'], 'Salom'
));

$results[0]->successful;   // true — sent as usual
$results[1]->successful;   // false
$results[1]->errorMessage; // "Phone [+998971111111] matches blocked prefix [99897]. ..."
```

## Logging

Events always fire; both logging channels are optional listeners layered on top.

### Database log (`SMS_LOG_DATABASE`, default off)

With `SMS_LOG_DATABASE=true`, every send — including failures — becomes an `sms_logs` row; webhook callbacks and status pulls update the row's `status` by `(driver, provider_message_id)`. Query it like any model:

```php
use Uzbek\Sms\Models\SmsLog;
use Uzbek\Sms\Enums\DeliveryStatus;

SmsLog::query()
    ->where('driver', 'eskiz')
    ->where('status', DeliveryStatus::Undelivered)
    ->where('created_at', '>=', now()->subDay())
    ->get();

SmsLog::query()->whereNotNull('error')->latest()->limit(20)->get(); // recent failures
```

While the channel stays off (the default), no rows are written — the events still fire and your app can persist whatever it wants.

### Debug log (`SMS_LOG_DEBUG`, default off)

Structured entries (driver, phone, provider message id, status, success flag, error) via Laravel's logger. Point it at a dedicated channel if you like:

```dotenv
SMS_LOG_DEBUG=true
SMS_LOG_CHANNEL=sms
```

```php
// config/logging.php
'channels' => [
    'sms' => [
        'driver' => 'daily',
        'path' => storage_path('logs/sms.log'),
        'days' => 14,
    ],
],
```

Debug entries never contain credentials, tokens or auth headers.

## Events

Listen in your app for anything the built-in listeners don't cover:

```php
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Events\DeliveryStatusUpdated;

Event::listen(SmsSent::class, function (SmsSent $event): void {
    if (! $event->message->successful) {
        Notification::route('slack', config('services.slack.ops'))
            ->notify(new SmsFailedNotification($event->message));
    }
});

Event::listen(DeliveryStatusUpdated::class, function (DeliveryStatusUpdated $event): void {
    // $event->driver, $event->providerMessageId, $event->status, $event->raw
});
```

`SmsSent` fires exactly once per message — successes and failures, single and bulk alike.

## Webhooks

Webhook routes register at `POST /{SMS_WEBHOOK_PATH}/{driver}` when `SMS_WEBHOOK_ENABLED=true` (off by default — send-only apps expose no endpoint). The route carries only the middleware from `config('sms.webhook.middleware')` — it deliberately sits outside the `web` group and CSRF, because providers POST server-to-server.

### PlayMobile

PlayMobile does not sign callbacks. The shared-secret token is **optional** — leave `PLAYMOBILE_WEBHOOK_SECRET` empty and callbacks are accepted without a token; set it and the token becomes mandatory and must match. Register this URL in the PlayMobile cabinet:

```
https://your-app.uz/sms/webhooks/playmobile?token=<PLAYMOBILE_WEBHOOK_SECRET>
```

Setting a secret is recommended, since the endpoint sits outside `web`/CSRF. Optionally pin the sender IPs in `config/sms.php`:

```php
'playmobile' => [
    // ...
    'allowed_ips' => ['185.8.212.47'],
],
```

Wrong token (when a secret is set) or a disallowed IP → 403. Valid callbacks update `sms_logs` (when the database log is on) and dispatch `DeliveryStatusUpdated` either way.

### Eskiz, TextUp, Sayqal

No webhook intake in v1 — poll with `checkStatus()`. Eskiz supports a `callback_url` on send (the config key is reserved); a POST to `/sms/webhooks/eskiz` currently returns 404 by design.

## Deployment and cache notes

Eskiz and TextUp tokens are cached and refreshed with a **single-flight** strategy: on a 401, one process takes an atomic lock, re-logs-in and stores the fresh token; concurrent processes wait, then adopt that token instead of logging in again.

- `SMS_CACHE_STORE` (empty = the app default) chooses where tokens live. Atomic locks work on `file`, `database`, `array`, `redis`, `memcached` and `dynamodb` stores — no extra infrastructure needed.
- **Multi-server / serverless (Vapor):** point `SMS_CACHE_STORE` at a *shared* store (`redis`, `database`, `dynamodb`). With a per-container cache the package still works, but each container logs in once and refreshes independently.
- If the chosen store has no lock support, refresh degrades to a lock-less re-login. Duplicate concurrent logins are harmless (providers issue independent tokens); tokens are never lost.
- `SMS_CACHE_PREFIX` (default `sms`) namespaces every key the package writes (`sms:eskiz:token`, `sms:eskiz:token:lock`, ...). Change it if you run several apps against one Redis.

## Adding a new driver

Adding a provider touches nothing existing: extend `AbstractDriver`, implement `doSend()` + `resolveAuthenticator()` + `mapStatus()`, add capability interfaces if the provider supports them, add a config block, and register one line in the `DriverFactory` map.

A complete worked example — a fictional provider with API-key auth and status pull:

```php
<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Uzbek\Sms\Authenticators\ApiKeyAuthenticator;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Enums\DeliveryStatus;

final class AcmeDriver extends AbstractDriver implements ChecksDeliveryStatus
{
    public function name(): string
    {
        return 'acme';
    }

    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        return new ApiKeyAuthenticator('X-API-Key', (string) $config['api_key']);
    }

    protected function doSend(string $phone, string $text): SentMessage
    {
        $phone = $this->normalizePhone($phone);

        $response = $this->http()->post('messages', [
            'to' => $phone,
            'body' => $text,
            'sender' => $this->config['from'],
        ]);

        return SentMessage::success(
            driver: $this->name(),
            phone: $phone,
            text: $text,
            providerMessageId: (string) $response->json('message_id'),
            raw: (array) $response->json(),
        );
    }

    public function checkStatus(string $providerMessageId): DeliveryStatus
    {
        $response = $this->http()->get("messages/{$providerMessageId}")->throw();

        return $this->mapStatus((string) $response->json('state'));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function mapStatus(string $status): DeliveryStatus
    {
        return match ($status) {
            'queued' => DeliveryStatus::Pending,
            'sent' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'undelivered' => DeliveryStatus::Undelivered,
            'failed' => DeliveryStatus::Failed,
            default => DeliveryStatus::Unknown,
        };
    }
}
```

Then the config block:

```php
// config/sms.php
'acme' => [
    'enabled' => (bool) env('ACME_ENABLED', true),
    'base_url' => env('ACME_BASE_URL', 'https://api.acme.example/v1'),
    'api_key' => env('ACME_API_KEY'),
    'from' => env('ACME_FROM'),
    'http_options' => [],
],
```

And one line in `DriverFactory::DRIVERS`:

```php
'acme' => AcmeDriver::class,
```

Everything else — events, logging, retries, prefix rules, the fluent builder, webhook routing — comes from the base class and the provider wiring for free. If the provider has a native bulk endpoint, override the protected `doSendMany()` and route results through `finalizeBulk()`; if it signs requests or logs in for a token, reuse `SignedTokenAuthenticator` or `LoginTokenAuthenticator` the same way the built-in drivers do.

## Testing

```bash
composer test
```

The suite uses Pest with `Http::fake()` throughout — no network access, no live credentials.

## License

MIT. See [LICENSE.md](LICENSE.md).
