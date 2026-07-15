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

Pick a default provider and configure the ones you use. Keep the published `config/sms.php` complete — Laravel merges package config at the top level only, so a hand-written partial file would drop the `webhook`/`logging`/`cache` sections entirely.

Each entry under the `providers` array is a **named provider** — the identifier you pass to `sms()`, `DriverFactory::make()` and `useFallback()`, and the value stored in `sms_logs.provider`. Every provider block starts with a `driver` key naming the implementation that actually sends for it:

```php
// config/sms.php
'providers' => [
    'eskiz' => [
        'driver' => 'eskiz',
        // ...
    ],
    'playmobile' => [
        'driver' => 'playmobile',
        // ...
    ],
    'textup' => [/* ... */],
    'sayqal' => [/* ... */],
],
```

By default each provider is named after its driver, but that is just a convention, not a rule — see [Multiple accounts / named providers](#multiple-accounts--named-providers) below.

Full `.env` reference:

```dotenv
# Core
SMS_PROVIDER=eskiz               # default provider: eskiz | playmobile | textup | sayqal
SMS_WEBHOOK_ENABLED=false        # set true to receive delivery callbacks
SMS_WEBHOOK_PATH=sms/webhooks    # webhook base path
SMS_LOG_DATABASE=false           # set true to log every send to sms_logs
SMS_LOG_DEBUG=false              # write structured entries to the Laravel log
SMS_LOG_CHANNEL=                 # Monolog channel for the debug log (empty = default)
SMS_CACHE_STORE=                 # cache store for auth tokens (empty = app default)
SMS_CACHE_PREFIX=sms             # prefix for all package cache keys
SMS_SILENT=false                 # suppress the unsupported-bulk-fallback warning log

# Eskiz — https://notify.eskiz.uz
ESKIZ_ENABLED=true
ESKIZ_EMAIL=account@example.com
ESKIZ_PASSWORD=secret
ESKIZ_FROM=4546
ESKIZ_TOKEN_TTL=2592000          # seconds; Eskiz JWTs live ~30 days
ESKIZ_CALLBACK_ENABLED=false     # set true to send callback_url with every message
ESKIZ_CALLBACK_URL=              # explicit callback URL (empty = the package webhook URL)
ESKIZ_WEBHOOK_SECRET=            # random string; token check for incoming Eskiz callbacks
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

> **TextUp validates against approved templates.** The message text must exactly match one of your account's approved templates (placeholders filled per the template's pattern, trailing newline included). Text that matches no approved template is rejected by TextUp before delivery. Get your templates approved in the TextUp cabinet first.

Each provider block in `config/sms.php` also accepts `http_options` — raw Guzzle options passed to every request. Uzbek providers usually require a whitelisted static IP, so a proxy is a first-class concern:

```php
'providers' => [
    'eskiz' => [
        // ...
        'http_options' => [
            'proxy' => 'http://10.0.5.1:3128',
            'timeout' => 10,
        ],
    ],
],
```

### Multiple accounts / named providers

Give two provider entries the same `driver` to run more than one account through the same implementation — useful when, say, marketing and transactional traffic should log in as different Eskiz accounts:

```php
// config/sms.php
'providers' => [
    'eskiz'     => ['driver' => 'eskiz', 'email' => env('ESKIZ_EMAIL'), /* ... */],
    'marketing' => ['driver' => 'eskiz', 'email' => env('ESKIZ_MKTG_EMAIL'), /* ... */],
],

// usage
sms('marketing')->to('+998901234567')->text('Salom')->send();
```

`sms(...)`, `->useFallback(...)`, the webhook URL segment (`/sms/webhooks/{provider}`), and the `sms_logs.provider` column all key off the **provider name** (`eskiz`, `marketing`) — not the `driver` they happen to share. Only the four built-in facades (`EskizSms`, `PlayMobileSms`, `TextUpSms`, `SayqalSms`) exist out of the box, so reach for `sms('marketing')` or `app('sms.provider.marketing')` for any extra named provider.

### Custom driver

A `driver` value can be a built-in name (`eskiz`, `playmobile`, `textup`, `sayqal`) or a fully-qualified class extending `Uzbek\Sms\Drivers\AbstractDriver` — no changes to the package itself required:

```php
'providers' => [
    'inhouse' => ['driver' => \App\Sms\InHouseDriver::class, /* ... */],
],
```

To give a custom class a short name (or replace a built-in implementation), register it in the top-level `drivers` map — it is merged over the built-ins, so the same alias wins:

```php
'drivers' => [
    'inhouse' => \App\Sms\InHouseDriver::class,  // new alias
    'eskiz' => \App\Sms\PatchedEskizDriver::class, // overrides the built-in
],
'providers' => [
    'inhouse' => ['driver' => 'inhouse', /* ... */],
],
```

See [Adding a new driver](#adding-a-new-driver) for what the class needs to implement.

## Sending

### Fluent

```php
use Uzbek\Sms\Contracts\Driver;

$message = app(Driver::class)
    ->to('+998901234567')
    ->text('Your code is 4821')
    ->send();
```

The builder also carries per-message options — each just overrides config for that one send:

```php
sms('textup')
    ->to('+998901234567')
    ->text('Your code is 1234')
    ->otp()                     // is_otp = true (isOtp() alias)
    ->from('MyBrand')           // sender / nickname override (nickname() alias)
    ->as(['email' => $tenant->email, 'password' => $tenant->pw])  // runtime credentials (usingCredentials() alias)
    ->send();
```

A builder is single-use: calling `send()` a second time on the same `PendingMessage` (or bulk `many()` builder) throws a `LogicException` instead of sending the SMS again. Build a new message for each send. A validation failure (missing `to()`/`text()`) does *not* burn the builder — fix it and `send()` again.

### Direct

```php
$message = app(Driver::class)->send('+998901234567', 'Your code is 4821');
```

### The `sms()` helper

```php
sms()->send('+998901234567', 'Your code is 4821');            // default provider
sms('playmobile')->to('+998901234567')->text('Salom')->send(); // named provider
```

The helper is a thin wrapper over the factory — `sms()` is `DriverFactory::default()`, `sms('eskiz')` is `DriverFactory::make('eskiz')`, with the same fail-fast exceptions for unknown or disabled providers.

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

Every provider has its own facade, plus `Sms` for the default provider. Each one proxies the exact same singleton the factory returns, so enabled flags, prefix rules, events and logging all apply:

```php
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Facades\EskizSms;
use Uzbek\Sms\Facades\PlayMobileSms;
use Uzbek\Sms\Facades\TextUpSms;
use Uzbek\Sms\Facades\SayqalSms;

Sms::send('+998901234567', 'Your code is 4821');          // the default provider
EskizSms::to('+998901234567')->text('Salom')->send();     // a specific provider
SayqalSms::checkStatus($message->providerMessageId);      // capability methods too
```

Root aliases are auto-registered, so `\EskizSms::send(...)` works without an import. A facade whose provider is disabled throws `DriverDisabledException` on its first call — same fail-fast rule as the factory.

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

`SentMessage` fields: `provider`, `phone`, `text`, `status` (`DeliveryStatus` enum), `successful`, `providerMessageId`, `errorMessage`, `raw` (the provider response, for debugging), `fallbackFrom` (the primary provider whose failed attempt this result replaced — `null` unless a fallback ran, see [Fallback provider](#fallback-provider)), `debug` (HTTP trace of the send — `null` unless enabled, see [Debug mode](#debug-mode)).

The only exceptions you will ever see are configuration errors at resolution time — see below.

## Queued and scheduled sends

Both builders queue instead of blocking the request — `queue()` for now, `later()` for a scheduled send:

```php
Sms::to('+998901234567')->text('Salom')->queue();          // default queue
Sms::to('+998901234567')->text('Salom')->queue('sms');     // named queue
Sms::to('+998901234567')->text('Salom')->later(now()->addMinutes(10));
Sms::many($messages)->later(now()->setTime(9, 0), 'sms');  // marketing at 09:00
```

The fallback provider is resolved at queue time; per-message options and runtime credentials travel with the job. Inside the job a provider failure becomes the usual `SentMessage::failed` + events — the job doesn't throw, so queue retries stay reserved for infrastructure errors. Two builder features are sync-only and throw a `LogicException` if queued: `debug()` and `useFallback()` with a Closure predicate.

## Duplicate protection

`dedupe(key, ttl = 86400)` on either builder makes a send at-most-once per key: the key is reserved in the cache before transport, so a double-click, a duplicated webhook, or a retried queue job inside the TTL is skipped instead of resent — the skipped result comes back unsuccessful with a "duplicate" message, and no fallback fires for it:

```php
Sms::to($phone)->text("Kod: {$code}")->dedupe("otp:{$userId}", 300)->send();
Sms::to($phone)->text("Kod: {$code}")->dedupe("otp:{$userId}", 300)->queue(); // key checked when the job runs
```

If the cache store is unavailable the send proceeds without protection (a warning is logged) — losing dedupe beats losing messaging.

## Segments and encoding

One Cyrillic character switches an SMS from GSM-7 (160 chars/segment) to UCS-2 (70 chars/segment) — doubling the cost of a "160-char" message without warning. Check before or after sending:

```php
use Uzbek\Sms\Support\SegmentCalculator;

$info = SegmentCalculator::for('Салом дунё');
$info->encoding;    // SmsEncoding::Ucs2
$info->length;      // 10 (UTF-16 units; GSM extension chars like { count twice)
$info->segments;    // 1
$info->perSegment;  // 70

$message->segments(); // same SegmentInfo from any SentMessage
```

## Notifications channel

Register nothing — the `sms` channel is ready once the package is installed:

```php
use Illuminate\Notifications\Notification;
use Uzbek\Sms\Notifications\SmsMessage;

class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    // a plain string uses the default provider...
    public function toSms(object $notifiable): string|SmsMessage
    {
        return 'Buyurtmangiz jo\'natildi!';

        // ...or take full control:
        return SmsMessage::create('Kod: 1234')
            ->provider('playmobile')
            ->from('3700')
            ->otp()
            ->useFallback('eskiz');   // or ->withoutFallback()
    }
}
```

The recipient comes from `routeNotificationForSms()` on the notifiable (or `SmsMessage->to()` to override); on-demand works too: `Notification::route('sms', '+998901234567')->notify(...)`. A missing route or missing `toSms()` skips the notification with a logged warning — it never throws.

## Balance

Providers with a balance endpoint implement `ChecksBalance` (currently Eskiz; PlayMobile runs mixed prepaid/postpaid accounts and exposes no endpoint):

```php
use Uzbek\Sms\Contracts\ChecksBalance;

$driver = sms('eskiz');

if ($driver instanceof ChecksBalance) {
    $driver->balance()->amount;   // 150000.0 (UZS)
}
```

Set `ESKIZ_LOW_BALANCE_THRESHOLD` and every `balance()` call below it dispatches `Uzbek\Sms\Events\LowBalanceDetected` — listen to alert ops before the account runs dry. Schedule a periodic `balance()` call yourself if you want continuous monitoring.

## Health checks

`Sms::health('eskiz')` returns a `HealthStatus` (`healthy` true / false / null-unknown + message); `Sms::health()` probes every configured provider. It never throws — unknown providers, broken check classes, and probe exceptions all come back as failed statuses, ready for a monitoring endpoint:

```php
Sms::health('eskiz')->healthy;   // true — Eskiz ships a built-in probe (login + balance)
Sms::health('sayqal')->healthy;  // null — no probe configured
```

Register your own probe per provider — the config class wins over the driver's built-in:

```php
// implements Uzbek\Sms\Contracts\HealthCheck: check(Driver $driver): HealthStatus
'providers' => ['playmobile' => [/* ... */ 'health_check' => \App\Sms\PlayMobileProbe::class]],
```

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

## Switching and combining providers

The default provider comes from `SMS_PROVIDER`. To use a specific provider regardless of the default, resolve it by name:

```php
use Uzbek\Sms\DriverFactory;

$factory = app(DriverFactory::class);

$factory->make('eskiz')->send($phone, $text);      // marketing route
$factory->make('playmobile')->send($phone, $otp);  // transactional route
```

Or reach for the per-provider facades — `EskizSms::send($phone, $text)` is the same instance behind a static face.

## Runtime credentials

Pass credentials (or any config keys) at resolution time to send from a different account than `config/sms.php` — useful for multi-tenant apps where each tenant has its own provider login:

```php
use Uzbek\Sms\DriverFactory;

app(DriverFactory::class)
    ->make('eskiz', ['email' => $tenant->eskiz_email, 'password' => $tenant->eskiz_password])
    ->send($phone, $text);

// or via the helper
sms('eskiz', ['email' => $tenant->eskiz_email, 'password' => $tenant->eskiz_password])->send($phone, $text);
```

Overrides are merged over the provider's config block. For token drivers (Eskiz, TextUp) each distinct credential set gets its **own cached token**, keyed by a hash of the credentials — so tenants never share or clobber each other's tokens, and the single-flight refresh still applies per account. Overriding a non-credential key (below) reuses the configured account's token, so it costs no extra login.

The same mechanism sets any per-message option. TextUp's `isOtp`, for example, is per send:

```php
sms('textup', ['is_otp' => true])->send($phone, 'Your code is 1234');  // this message only
sms('textup')->send($phone, $text);                                    // normal
```

## Transient retries

Off by default (a 5xx or timeout fails the send immediately, as before). Opt in per provider:

```php
'providers' => [
    'eskiz' => [
        // ...
        'retry' => ['times' => 3, 'sleep' => 200],   // total attempts, ms between
    ],
],
```

Retried: connection failures and 5xx responses. Never retried: 4xx client errors. The built-in 401 refresh-and-retry keeps working either way.

## Circuit breaker

Off by default. When enabled, N consecutive failures open the circuit: further sends fast-fail without touching the provider for the cooldown window, and your configured fallback provider takes over immediately (a fast-failed message is a failed message, so the normal fallback path fires):

```php
'providers' => [
    'eskiz' => [
        // ...
        'circuit_breaker' => ['threshold' => 5, 'cooldown' => 60],   // failures, seconds
    ],
],
```

Any success closes the counter; prefix-rule rejections and fake-mode sends don't count. State lives in the same cache store as auth tokens; if the cache is unavailable the breaker simply stays closed.

## Fallback provider

For a single send, name a secondary provider to try when the primary fails:

```php
$message = sms('eskiz')
    ->to('+998901234567')
    ->text('Your code is 4821')
    ->useFallback('playmobile')
    ->send();
```

The primary sends once. If it returns an unsuccessful `SentMessage`, the fallback sends once and its result is returned with `fallbackFrom` set to the primary's name (`'eskiz'` here) — so a result whose `provider` differs from the facade or helper you called is always explained by a non-null `fallbackFrom`. A successful primary never contacts the fallback and leaves `fallbackFrom` as `null`. Pass a predicate to decide for yourself:

```php
->useFallback('playmobile', fn (SentMessage $sent) => $sent->status === DeliveryStatus::Failed)
```

Notes:
- **This is the single-send form** — `to()->...->send()` fails over as a whole. `sendMany()`/`many()` has its own per-message bulk fallback — see [Bulk fallback](#bulk-fallback) below.
- **One secondary** — the fallback is not itself retried.
- **Each attempt is real:** a failed primary and a successful fallback each fire `SmsSent` and (with the database log on) write their own `sms_logs` row — an honest record of "eskiz failed, playmobile delivered."
- Fluent overrides (`otp()`, `from()`, `as()`) apply to the **primary only**; the fallback uses its own config.
- An unknown or disabled fallback provider throws the usual resolution exception, but only if the fallback is actually triggered.

### Bulk fallback

`sendMany()` takes the same idea as `useFallback()`, but per-message: only the recipients whose primary result failed are retried through the fallback, not the whole batch. Two equivalent forms:

```php
// Fluent, via many()
$results = sms('sayqal')->many($messages)->useFallback('eskiz')->send();

// Params, directly on sendMany()
$results = sms('sayqal')->sendMany($messages, fallback: 'eskiz', fallbackWhen: fn (SentMessage $m) => ! $m->successful);
```

Both drive the exact same call — `many($messages)->useFallback(...)->send()` just collects the arguments and calls `sendMany($messages, $fallback, $fallbackWhen)` for you. The default predicate is "not successful"; pass your own `fallbackWhen` to decide differently. Only the messages that match are collected and re-sent as one batch through the fallback provider; the returned `Collection` keeps every recipient in their original position, whichever provider's result ended up there. Entries that went through the fallback carry `fallbackFrom` with the primary provider's name; untouched entries keep it `null`.

Not every driver can be trusted with a partial retry — it needs to report *which individual message* failed, not just an all-or-nothing batch result. `Uzbek\Sms\Contracts\SupportsBulkFallback` is the marker interface that says a driver's `sendMany()` qualifies (either it sends one HTTP request per message under the hood, or its native batch endpoint reports success per item). Only `SayqalDriver` implements it today; `EskizDriver`, `PlayMobileDriver` and `TextUpDriver` do not. Passing `fallback` to one of those is a no-op — the primary results come back untouched — and a warning is logged (`SMS provider [x] does not support bulk fallback; returning primary results ...`). Set `SMS_SILENT=true` (`sms.silent`) to suppress that warning project-wide, e.g. if you deliberately pass a fallback to every provider regardless of support.

### Default fallback provider

Configure a fallback once per provider instead of calling `useFallback()` at every call site:

```php
// config/sms.php
'providers' => [
    'eskiz' => [
        'driver' => 'eskiz',
        'fallback' => 'sayqal',
        // ...
    ],
],
```

It is picked up automatically by both fluent builders whenever the call site doesn't set its own `useFallback(...)`:

```php
sms('eskiz')->to('+998901234567')->text('Salom')->send();   // falls back to sayqal on failure
sms('eskiz')->many($messages)->send();                      // same, per-message
```

An explicit `useFallback(...)` always overrides the configured default. Call `withoutFallback()` to send with no fallback at all, configured or not:

```php
sms('eskiz')->to('+998901234567')->text('Salom')->withoutFallback()->send();
```

The configured default only applies through the fluent builders. The bare `send($phone, $text)` and the raw `sendMany($messages)` call (no `fallback` argument) are unchanged — they never consult it.

## Enabling and disabling providers

Every provider block has an `enabled` flag (`ESKIZ_ENABLED`, `PLAYMOBILE_ENABLED`, ...). Configuration problems fail fast at resolution time — before any HTTP request:

```php
$factory->make('nexmo');
// Uzbek\Sms\Exceptions\UnknownProviderException:
// SMS provider [nexmo] is not defined. Add a config/sms.php providers block.

// with ESKIZ_ENABLED=false:
$factory->make('eskiz');
// Uzbek\Sms\Exceptions\DriverDisabledException:
// SMS provider [eskiz] is disabled. Enable it via sms.providers.eskiz.enabled.
```

A third exception, `UnknownDriverException`, covers the narrower case where the provider block resolves but its `driver` key names neither a built-in driver nor a valid `AbstractDriver` subclass (see [Custom driver](#custom-driver)). All three extend `Uzbek\Sms\Exceptions\SmsException`. A disabled *default* provider throws on the first `app(Driver::class)` resolution, and webhook requests addressed to a disabled or unknown provider return 404 without leaking why.

## Bulk chunking and rate limiting

Off by default (one native batch request, as before). Opt in per provider for large campaigns:

```php
'providers' => [
    'eskiz' => [
        // ...
        'bulk' => ['chunk' => 500, 'per_second' => 100],
    ],
],
```

`chunk` splits `sendMany()` into batches of that size; `per_second` paces them (and implies a chunk size when `chunk` is not set). Result order and per-message events are preserved across chunks.

## Restricting recipients by prefix

Each provider block accepts a `prefixes` section — an allowed list, a blocked list, or both. By default both are empty and every number goes through.

```php
// config/sms.php
'providers' => [
    'eskiz' => [
        // ...
        'prefixes' => [
            'allowed' => [],            // non-empty = only these prefixes may receive SMS
            'blocked' => ['99833'],     // always rejected; wins over the allowed list
        ],
    ],
],
```

Rules are **per provider** — blocking a prefix on `eskiz` says nothing about `playmobile`. Matching is digit-based, so `+998 33`, `99833` and `998-33` all mean the same prefix, whatever format the number arrives in.

A prohibited number is rejected *before* any HTTP request and follows the usual pipeline rules: you get a `SentMessage::failed(...)` carrying the `ProhibitedPhoneException` message, `SmsSent` still fires, the attempt is logged, and the rest of a bulk send continues untouched:

```php
config(['sms.providers.eskiz.prefixes.blocked' => ['99897']]);

$results = app(Driver::class)->sendMany(OutboundMessage::sameText(
    ['+998901111111', '+998971111111'], 'Salom'
));

$results[0]->successful;   // true — sent as usual
$results[1]->successful;   // false
$results[1]->errorMessage; // "Phone [+998971111111] matches blocked prefix [99897]. ..."
```

### Dynamic rules (database, admin panel, ...)

When the lists must change at runtime — an admin panel, a `blocked_numbers` table, an external service — point `prefix_rules` at a class implementing `Uzbek\Sms\Contracts\PrefixRules`:

```php
use Uzbek\Sms\Contracts\PrefixRules;

final class DatabasePrefixRules implements PrefixRules
{
    public function allowlist(string $provider): array
    {
        return $this->load($provider)['allowed'];
    }

    public function blocklist(string $provider): array
    {
        return $this->load($provider)['blocked'];
    }

    private function load(string $provider): array
    {
        return cache()->remember("sms-prefixes:{$provider}", 60, fn (): array => [
            'allowed' => SmsPrefix::query()->where('provider', $provider)->where('type', 'allowed')->pluck('prefix')->all(),
            'blocked' => SmsPrefix::query()->where('provider', $provider)->where('type', 'blocked')->pluck('prefix')->all(),
        ]);
    }
}
```

```php
// config/sms.php — global, or per provider (per-provider wins):
'prefix_rules' => \App\Sms\DatabasePrefixRules::class,
'providers' => [
    'eskiz' => [
        // ...
        'prefix_rules' => \App\Sms\EskizPrefixRules::class,
    ],
],
```

The class is resolved from the container **once per send call** (single or bulk), so rule changes apply immediately without rebuilding anything — cache inside the class if the lookup is expensive. Neither list is required: return `[]` from `allowlist()` for no allow-restriction, and the `blocklist()` always wins. The lists are merged with the static `prefixes` config and normalized the same way. If the class throws or does not implement the contract, the package logs a warning (silenced by `SMS_SILENT`) and falls back to the static lists — sending never goes down with your database. Keep legally-required blocks in the static config for exactly that reason.

## Fake mode

For local development and staging: sends look completely real — same `SentMessage`, same `SmsSent` events, same database/debug logging, same fallback behavior — but no HTTP call ever leaves the machine (not even auth). Controlled by env only:

```dotenv
SMS_FAKE=true
SMS_FAKE_SUCCESS_RATE=0.7   # optional; default 1.0
```

```php
$message = sms('textup')->to('+998901234567')->text('Salom')->send();

$message->successful;         // true (or false, by the success-rate roll)
$message->providerMessageId;  // "fake-01KX..."
$message->raw;                // ['fake' => true]
```

`success_rate` is a probability from 0 to 1 applied per message: `1.0` (default) — every send succeeds; `0.7` — roughly 70% succeed; `0` — every send fails with `errorMessage` `"Simulated failure (fake mode)."`. A blank value counts as unset; anything non-numeric or outside 0..1 (say, `90` meant as a percentage) falls back to `1.0` and logs a warning (suppressed by `SMS_SILENT`). A faked failure drives the normal pipeline: fallback providers kick in (and roll the same rate themselves), `fallbackFrom` gets set, failed sends are logged — so you can rehearse your error handling end-to-end without a provider account.

Still real in fake mode: recipient prefix rules (a blocked number is rejected as usual, not rolled), events, logging, the resend guard. Not faked: `checkStatus()` — it would hit the real API, and fake message ids don't exist there.

## Debug mode

When a send misbehaves — wrong provider answering, silent fallback, auth mysteriously failing — turn on debug for that one send and inspect exactly what went over the wire:

```php
$message = sms('textup')
    ->to('+998901234567')
    ->text('Salom')
    ->debug()
    ->send();

$message->debug;
// [
//   ['type' => 'request', 'method' => 'POST', 'url' => 'https://api-auth.textup.uz/v1/login',
//    'request' => ['email' => 'a@b.uz', 'password' => '••••••'], 'status' => 200,
//    'response' => ['accessToken' => '••••••', ...], 'duration_ms' => 182],
//   ['type' => 'request', 'method' => 'POST', 'url' => 'https://sms-api.textup.uz/v1/send', ...],
// ]
```

Code-only by design: there is no config key or env var, so debug can't be left on in production by accident. `debug()` exists on both builders — `to()->...->debug()->send()` and `many()->...->debug()->send()`.

What lands in the trace:

- **Every HTTP exchange** during the send — auth/login calls, the send itself, and any fallback provider's traffic — as `request` entries: `method`, `url`, `request` body, `status`, `response` body, `duration_ms`. Network-level failures appear as `connection_failed` entries.
- **Fallback decisions** as `['type' => 'fallback', 'from' => 'textup', 'to' => 'playmobile']` entries, in order between the two providers' exchanges.
- **Failed final results** as `exception` entries carrying the provider, phone, and `errorMessage` — one per failed message on bulk sends.

Credentials are always redacted (`password`, `secret_key`, `token`, `accessToken` → `••••••`), request/response bodies included; headers are not captured at all. A non-JSON response body is stored verbatim — unless the request carried credentials (a login call), in which case the whole body is masked since it is likely a raw token. With debug off (the default) `SentMessage->debug` stays `null` and nothing is collected — zero overhead.

Bulk note: batch HTTP requests cover many recipients at once, so every `SentMessage` in a bulk result carries the *whole* send's trace, not a per-message slice.

## Logging

Events always fire; both logging channels are optional listeners layered on top.

### Database log (`SMS_LOG_DATABASE`, default off)

With `SMS_LOG_DATABASE=true`, every send — including failures — becomes an `sms_logs` row; webhook callbacks and status pulls update the row's `status` by `(provider, provider_message_id)`. Query it like any model:

```php
use Uzbek\Sms\Models\SmsLog;
use Uzbek\Sms\Enums\DeliveryStatus;

SmsLog::query()
    ->where('provider', 'eskiz')
    ->where('status', DeliveryStatus::Undelivered)
    ->where('created_at', '>=', now()->subDay())
    ->get();

SmsLog::query()->whereNotNull('error')->latest()->limit(20)->get(); // recent failures
```

While the channel stays off (the default), no rows are written — the events still fire and your app can persist whatever it wants.

### Debug log (`SMS_LOG_DEBUG`, default off)

Structured entries (provider, phone, provider message id, status, success flag, error) via Laravel's logger. Point it at a dedicated channel if you like:

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
    // $event->provider, $event->providerMessageId, $event->status, $event->raw
});
```

`SmsSent` fires exactly once per message — successes and failures, single and bulk alike.

Timing caveat: the event fires inside the driver, before the pending layer stamps `fallbackFrom` and `debug` on the returned `SentMessage` — listeners always see those two fields as `null`. Fallback attribution is still reconstructible from the log: a failed primary attempt and its fallback's send each fire their own event and write their own row.

## Webhooks

Webhook routes register at `POST /{SMS_WEBHOOK_PATH}/{provider}` when `SMS_WEBHOOK_ENABLED=true` (off by default — send-only apps expose no endpoint). The route carries only the middleware from `config('sms.webhook.middleware')` — it deliberately sits outside the `web` group and CSRF, because providers POST server-to-server.

Every verified webhook dispatches `DeliveryStatusUpdated` per parsed report (updating `sms_logs` when the database log is on). What happens next is configurable per provider with `webhook_handler` — see [Custom handlers](#custom-handlers) below. Without a handler, the webhook is written to the log (`SMS_LOG_CHANNEL`) so no callback ever disappears silently.

### PlayMobile

PlayMobile does not sign callbacks. The shared-secret token is **optional** — leave `PLAYMOBILE_WEBHOOK_SECRET` empty and callbacks are accepted without a token; set it and the token becomes mandatory and must match. Register this URL in the PlayMobile cabinet:

```
https://your-app.uz/sms/webhooks/playmobile?token=<PLAYMOBILE_WEBHOOK_SECRET>
```

Setting a secret is recommended, since the endpoint sits outside `web`/CSRF. Optionally pin the sender IPs in `config/sms.php`:

```php
'providers' => [
    'playmobile' => [
        // ...
        'allowed_ips' => ['185.8.212.47'],
    ],
],
```

Wrong token (when a secret is set) or a disallowed IP → 403. Valid callbacks update `sms_logs` (when the database log is on) and dispatch `DeliveryStatusUpdated` either way.

### Eskiz

Eskiz pushes delivery reports to a `callback_url` sent with each message. Set `ESKIZ_CALLBACK_ENABLED=true` and the driver attaches one automatically: the explicit `ESKIZ_CALLBACK_URL` if set, otherwise the package webhook URL (`/sms/webhooks/eskiz`, requires `SMS_WEBHOOK_ENABLED=true`). When `ESKIZ_WEBHOOK_SECRET` is set, the auto-generated URL carries `?token=<secret>` and incoming callbacks must present it; `allowed_ips` pinning works the same as for PlayMobile. With everything off (the default), no `callback_url` is sent at all — poll with `checkStatus()` instead.

### TextUp, Sayqal

No webhook intake — poll with `checkStatus()`, or configure a [custom handler](#custom-handlers) to accept whatever these providers POST.

### Custom handlers

Set `webhook_handler` on any provider to process callbacks yourself. The class is resolved from the container and must implement `Uzbek\Sms\Contracts\WebhookHandler`:

```php
use Illuminate\Http\Request;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\WebhookHandler;
use Uzbek\Sms\Data\DeliveryReport;

final class EskizWebhookHandler implements WebhookHandler
{
    /** @param list<DeliveryReport> $reports */
    public function handle(Request $request, Driver $driver, array $reports): void
    {
        // notify your own systems, enqueue jobs, ...
    }
}
```

```php
'providers' => [
    'eskiz' => [
        // ...
        'webhook_handler' => \App\Sms\EskizWebhookHandler::class,
    ],
],
```

`DeliveryStatusUpdated` events and the database log keep working alongside the handler — it is an addition, not a replacement. A handler also unlocks the endpoint for drivers that cannot parse webhooks themselves (`$reports` arrives empty and the raw `Request` is yours); in that case the driver performs no verification, so the handler owns security.

## Deployment and cache notes

Eskiz and TextUp tokens are cached and refreshed with a **single-flight** strategy: on a 401, one process takes an atomic lock, re-logs-in and stores the fresh token; concurrent processes wait, then adopt that token instead of logging in again.

- `SMS_CACHE_STORE` (empty = the app default) chooses where tokens live. Atomic locks work on `file`, `database`, `array`, `redis`, `memcached` and `dynamodb` stores — no extra infrastructure needed.
- **Multi-server / serverless (Vapor):** point `SMS_CACHE_STORE` at a *shared* store (`redis`, `database`, `dynamodb`). With a per-container cache the package still works, but each container logs in once and refreshes independently.
- If the chosen store has no lock support, refresh degrades to a lock-less re-login. Duplicate concurrent logins are harmless (providers issue independent tokens); tokens are never lost.
- `SMS_CACHE_PREFIX` (default `sms`) namespaces every key the package writes (`sms:eskiz:token`, `sms:eskiz:token:lock`, ...). Change it if you run several apps against one Redis.

## Adding a new driver

Adding a provider touches nothing existing: extend `AbstractDriver`, implement `doSend()` + `resolveAuthenticator()` + `mapStatus()`, add capability interfaces if the provider supports them, add a config block, and register the short name (`'acme'`) in the `drivers` map of `config/sms.php` so it is available everywhere. Or skip the alias entirely and reference the class directly in a provider's `driver` key — see [Custom driver](#custom-driver).

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
            provider: $this->name(),
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
'providers' => [
    'acme' => [
        'driver' => 'acme',
        'enabled' => (bool) env('ACME_ENABLED', true),
        'base_url' => env('ACME_BASE_URL', 'https://api.acme.example/v1'),
        'api_key' => env('ACME_API_KEY'),
        'from' => env('ACME_FROM'),
        'http_options' => [],
    ],
],
```

And one line in the `drivers` map of `config/sms.php`:

```php
'drivers' => [
    'acme' => AcmeDriver::class,
],
```

Everything else — events, logging, retries, prefix rules, the fluent builder, webhook routing — comes from the base class and the provider wiring for free. If the provider has a native bulk endpoint, override the protected `doSendMany()` and route results through `finalizeBulk()`; if it signs requests or logs in for a token, reuse `SignedTokenAuthenticator` or `LoginTokenAuthenticator` the same way the built-in drivers do.

## Testing

### In your application

`Sms::fake()` swaps every provider for a recording stub — no HTTP, no auth, no events, no log rows — and unlocks assertions on the facade:

```php
use Uzbek\Sms\Facades\Sms;

public function test_welcome_sms_is_sent(): void
{
    Sms::fake();

    $this->post('/register', [...]);

    Sms::assertSent(fn ($m) => $m->phone === '+998901234567' && str_contains($m->text, 'Xush kelibsiz'));
    Sms::assertSentCount(1);
    Sms::assertSentTo('998901234567');   // digit-based, format-insensitive
    // Sms::assertNothingSent();
    // Sms::sent()                       // Collection<SentMessage> for custom expectations
}
```

Named providers, the `sms()` helper, per-provider facades and the fluent builders all route through the fake automatically.

### The package itself

```bash
composer test
```

The suite uses Pest with `Http::fake()` throughout — no network access, no live credentials.

## License

MIT. See [LICENSE.md](LICENSE.md).
