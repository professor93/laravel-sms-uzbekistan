# Laravel SMS Uzbekistan

One API over Uzbekistan's SMS providers — **Eskiz**, **PlayMobile (smsxabar)**, **TextUp**, **Sayqal** — plus your own drivers. Fallback, queueing, OTP, webhooks, templates, notifications channel, testing fake. Everything beyond plain sending is opt-in: unused features cost nothing and change nothing.

## Requirements

- PHP 8.2+, Laravel 11+
- Credentials for at least one provider

## Install

```bash
composer require uzbek/laravel-sms
php artisan vendor:publish --tag=sms-config
php artisan vendor:publish --tag=sms-migrations   # only for database logging
php artisan migrate
```

Keep the published `config/sms.php` complete — Laravel merges package config top-level only; a partial file drops whole sections.

## Quick start

```dotenv
SMS_PROVIDER=eskiz
ESKIZ_EMAIL=account@example.com
ESKIZ_PASSWORD=secret
```

```php
use Uzbek\Sms\Facades\Sms;

$message = Sms::to('+998901234567')->text('Salom!')->send();

$message->successful;        // bool — the pipeline never throws on transport errors
$message->providerMessageId; // for status checks
$message->errorMessage;      // set when failed
```

## Sending

```php
// Fluent — the main API
Sms::to('+998901234567')
    ->text('Salom!')
    ->from('4546')            // sender/nickname override
    ->otp()                   // OTP flag (TextUp tariff)
    ->useFallback('playmobile')
    ->send();

// Every entry point resolves the same drivers:
sms()->send($phone, $text);                    // helper, default provider
sms('playmobile')->to($phone)->text($t)->send();
app(\Uzbek\Sms\Contracts\Driver::class);       // DI, default provider
EskizSms::to($phone)->text($t)->send();        // per-provider facades
```

### Bulk

```php
use Uzbek\Sms\Data\OutboundMessage;

Sms::many(OutboundMessage::sameText(['+99890...', '+99891...'], 'Bir matn'))->send();

Sms::many([
    new OutboundMessage('+99890...', 'Birinchi'),
    new OutboundMessage('+99891...', 'Ikkinchi'),
])->send();   // Collection<SentMessage>, input order preserved
```

Native batch endpoints are used where they exist (Eskiz, PlayMobile); others loop per message.

### Results

`SentMessage` fields: `provider`, `phone`, `text`, `status` (enum), `successful`, `providerMessageId`, `errorMessage`, `raw`, `fallbackFrom` (primary provider when a fallback delivered), `debug`. Methods:

```php
$message->segments();       // SegmentInfo: encoding, length, segments, perSegment
$message->cost();           // segments × price_per_segment config; null without a price
$message->updateStatus(DeliveryStatus::Delivered);  // dispatches DeliveryStatusUpdated → syncs sms_logs
$message->refreshStatus();  // polls the provider (ChecksDeliveryStatus), then updateStatus()
```

## Queued and scheduled sends

```php
Sms::to($phone)->text($t)->queue();                        // default queue
Sms::to($phone)->text($t)->queue('sms');                   // named queue
Sms::to($phone)->text($t)->later(now()->addMinutes(10));   // delayed
Sms::many($messages)->later(now()->setTime(9, 0), 'sms');  // campaign at 09:00
```

Fallback resolves at queue time; options and runtime credentials travel with the job. Inside the job a provider failure becomes the usual failed `SentMessage` + events — queue retries stay for infrastructure errors. Sync-only (throw `LogicException` when queued): `debug()`, `useFallback()` with a Closure.

## Duplicate protection

```php
Sms::to($phone)->text("Kod: {$code}")->dedupe("otp:{$userId}", 300)->send();  // or ->queue()
```

At-most-once per key: reserved in cache before transport; a double click or requeued job inside the TTL is skipped (failed result, "duplicate" message, no fallback). Cache down → sends proceed with a warning.

## OTP

```php
use Uzbek\Sms\Enums\OtpStatus;

Sms::otp()->send('+998901234567');                          // default provider + template
Sms::otp()->send($phone, provider: 'playmobile');
Sms::otp()->send($phone, template: 'Sizning :app kodingiz: :code', params: ['app' => 'MyShop']);
Sms::otp()->send($phone, template: 'sms.otp', locale: 'ru'); // translation key = localized

match (Sms::otp()->verify($phone, $input)) {
    OtpStatus::Valid => '...',            // consumed, replay impossible
    OtpStatus::Invalid => '...',          // wrong code, attempts left
    OtpStatus::TooManyAttempts => '...',  // locked out, code discarded
    OtpStatus::Expired => '...',          // no active code
};
```

Codes are hashed at rest, single-use, attempt-limited, resend-throttled. `template` = raw string | `templates.list` name | translation key. Config: `sms.otp` (`length` 6, `ttl` 300, `max_attempts` 5, `resend_cooldown` 60, `template`). Cache down → no code is sent (it could never be verified).

## Notifications channel

```php
class OrderShipped extends Notification
{
    public function via(object $n): array { return ['sms']; }

    public function toSms(object $n): string|SmsMessage
    {
        return 'Buyurtma jo\'natildi!';   // default provider
        // or:
        return \Uzbek\Sms\Notifications\SmsMessage::create('Kod: 1234')
            ->provider('playmobile')->from('3700')->otp()->useFallback('eskiz');
    }
}
```

Recipient: `routeNotificationForSms()` on the notifiable, `SmsMessage->to()`, or on-demand `Notification::route('sms', $phone)`. Missing route/`toSms()` = logged skip, never a throw.

## Templates

```php
// config/sms.php
'templates' => [
    'enforce' => false,
    'list' => ['welcome' => 'Xush kelibsiz, :name!'],
],

Sms::to($phone)->template('welcome', ['name' => 'Ali'])->send();
```

`enforce=true` (matches Eskiz/TextUp moderation reality): builder text matching no template throws `TemplateViolationException` before transport — single, bulk, and queue paths. `:placeholders` match any value.

> **TextUp moderation:** the text must exactly match an approved template (placeholders filled, trailing newline included) or TextUp rejects it. Approve templates in the cabinet first — `enforce` helps you catch violations before the provider does.

## Fallback provider

```php
Sms::to($phone)->text($t)->useFallback('playmobile')->send();          // on failure, retry via playmobile
Sms::to($phone)->text($t)->useFallback('playmobile', fn ($m) => ...)->send(); // custom predicate
Sms::to($phone)->text($t)->withoutFallback()->send();
```

Per-provider default: `'fallback' => env('ESKIZ_FALLBACK')` — applied by the fluent builders, overridable per message. One level deep, result carries `fallbackFrom`. Bulk fallback retries only failed messages and needs the primary driver to declare `SupportsBulkFallback` (per-message senders like Sayqal); batch-optimistic drivers log a warning instead (silenced by `SMS_SILENT`).

## Resilience (all opt-in, per provider)

```php
'providers' => ['eskiz' => [
    // ...
    'retry' => ['times' => 3, 'sleep' => 200],                  // transient 5xx/timeout retries; 4xx never retried
    'circuit_breaker' => ['threshold' => 5, 'cooldown' => 60],  // N consecutive failures → fast-fail, fallback takes over
    'bulk' => ['chunk' => 500, 'per_second' => 100],            // split + pace sendMany()
]],
```

Defaults keep the old behavior: no transient retry (401 refresh-and-retry always works), breaker off, one batch request. Breaker state lives in the cache; cache down = breaker stays closed. Success closes the failure counter; prefix rejections and fake mode don't count.

## Restricting recipients by prefix

```php
'providers' => ['eskiz' => [
    'prefixes' => [
        'allowed' => [],          // non-empty = only these prefixes
        'blocked' => ['99833'],   // always rejected; wins over allowed
    ],
]],
```

Per provider, digit-normalized (`+998 33` == `99833`). A prohibited number fails **before** any HTTP with the usual pipeline: failed `SentMessage`, `SmsSent` event, log row; the rest of a bulk continues.

### Dynamic rules (database, admin panel)

```php
final class DatabasePrefixRules implements \Uzbek\Sms\Contracts\PrefixRules
{
    public function allowlist(string $provider): array { return $this->load($provider)['allowed']; }
    public function blocklist(string $provider): array { return $this->load($provider)['blocked']; }

    private function load(string $provider): array
    {
        return cache()->remember("sms-prefixes:{$provider}", 60, fn () => [/* query */]);
    }
}

// global or per provider (per-provider wins):
'prefix_rules' => \App\Sms\DatabasePrefixRules::class,
```

Resolved once per send call — instant rule changes; cache inside the class. Neither list required; blocklist wins; merged with the static config. Source failure = warning + static lists only, so keep legal blocks static.

## Webhooks

Routes register at `POST {SMS_WEBHOOK_PATH}/{provider}` when `SMS_WEBHOOK_ENABLED=true`. Outside `web`/CSRF (providers POST server-to-server); middleware from `sms.webhook.middleware`. Every verified webhook dispatches `DeliveryStatusUpdated` per report (updates `sms_logs` when database logging is on); without a handler it is also written to the log.

**PlayMobile** — register `https://your-app.uz/sms/webhooks/playmobile?token=<PLAYMOBILE_WEBHOOK_SECRET>` in the cabinet. Empty secret = token check skipped; set = enforced. Optional `allowed_ips` pinning — exact IPs and CIDR ranges, IPv4 + IPv6 (`['185.8.212.47', '10.0.0.0/8', '2001:db8::/32']`). Bad token/IP → 403.

**Eskiz** — set `ESKIZ_CALLBACK_ENABLED=true` and every send carries `callback_url`: the explicit `ESKIZ_CALLBACK_URL`, or the package webhook URL (needs `SMS_WEBHOOK_ENABLED=true`), with `?token=` appended when `ESKIZ_WEBHOOK_SECRET` is set. Same token/IP guards on intake.

**TextUp, Sayqal** — no webhook intake; poll `checkStatus()` / `refreshStatus()`, or accept their POSTs with a custom handler.

**Custom handlers** — per provider:

```php
final class EskizWebhookHandler implements \Uzbek\Sms\Contracts\WebhookHandler
{
    /** @param list<DeliveryReport> $reports */
    public function handle(Request $request, Driver $driver, array $reports): void { /* ... */ }
}

'providers' => ['eskiz' => ['webhook_handler' => \App\Sms\EskizWebhookHandler::class]],
```

Additive: events and DB logging keep working. A handler also unlocks the endpoint for drivers that can't parse webhooks (`$reports` empty, raw request yours, handler owns security). Wrong class → loud 500.

## Balance, health, stats, prune

```php
sms('eskiz')->balance()->amount;   // Eskiz implements ChecksBalance; PlayMobile is mixed pre/postpaid — no endpoint
// ESKIZ_LOW_BALANCE_THRESHOLD set → balance() below it dispatches LowBalanceDetected

Sms::health('eskiz');   // HealthStatus: healthy true/false/null-unknown + message; never throws
Sms::health();          // all providers; per-provider 'health_check' => FQCN overrides the built-in probe

Sms::stats(from: now()->subMonth(), provider: 'eskiz');
// StatsReport: total, byStatus, deliveryRate, totalCost, totalSegments
// needs database logging; missing table → available=false + message, no exception
```

```bash
php artisan sms:prune --days=90   # or SMS_LOG_PRUNE_DAYS + schedule it
```

Missing table / missing retention = friendly message, exit 0.

## Named providers, runtime credentials

Provider names are arbitrary keys — two Eskiz accounts are two entries sharing `'driver' => 'eskiz'`. The name flows through `sms_logs.provider`, webhooks, and fallback references.

```php
sms('eskiz', ['email' => $other, 'password' => $pass])->send(...);   // runtime credentials
Sms::to($phone)->text($t)->as(['email' => $e, 'password' => $p])->send();
```

Tokens cache per credential set — parallel accounts never collide.

## Custom drivers

```bash
php artisan make:sms-driver Acme   # app/Sms/AcmeDriver.php with every contract explained
```

```php
'drivers' => ['acme' => \App\Sms\AcmeDriver::class],    // alias (or override a built-in)
'providers' => ['acme' => ['driver' => 'acme', /* ... */]],
// or skip the alias: 'driver' => \App\Sms\AcmeDriver::class
```

Required: `doSend()` + `resolveAuthenticator()` (reuse `ApiKey`/`Bearer`/`Basic`/`SignedToken`/`LoginToken` authenticators). Optional capability contracts — add what the provider supports: `ChecksDeliveryStatus`, `HandlesWebhooks` (+ `VerifiesWebhookSecurity` trait), `ChecksBalance`, `ChecksHealth`, `SupportsBulkFallback` (marker: per-message bulk, safe to retry individually). Native batch → override `doSendMany()` through `finalizeBulk()`. Events, logging, retries, breaker, prefixes, chunking, fake mode, builders — inherited.

## Capability detection

```php
$driver = sms('eskiz');
$driver instanceof ChecksDeliveryStatus;  // checkStatus()
$driver instanceof ChecksBalance;         // balance()
$driver instanceof HandlesWebhooks;       // webhook intake
```

## Config overrides from the database

File config stays the base; a source supplies only changed keys (rotated credentials, `enabled` toggles):

```bash
php artisan vendor:publish --tag=sms-overrides-migration && php artisan migrate
```

```php
'config_overrides' => \Uzbek\Sms\Config\DatabaseProviderConfigOverrides::class,
'config_overrides_ttl' => 60,   // cache seconds; flush('eskiz') applies edits immediately

SmsProviderOverride::create(['provider' => 'eskiz', 'config' => ['from' => '5555']]);
```

Precedence: file < source < runtime credentials. Failing source = warning + file config. Any `ProviderConfigOverrides` implementation works (Redis, API, ...).

## Fake mode (env-driven)

```dotenv
SMS_FAKE=true
SMS_FAKE_SUCCESS_RATE=0.7   # 0..1; invalid → 1 + warning
```

Sends short-circuit before auth — zero provider traffic — while events, logging, prefixes, and fallback stay real. `fake-<ulid>` ids, `raw = ['fake' => true]`.

## Testing your app

```php
Sms::fake();   // every provider becomes a recorder: no HTTP, no events, no log rows

Sms::assertSent(fn ($m) => $m->phone === '+998901234567' && str_contains($m->text, 'kod'));
Sms::assertSentCount(1);
Sms::assertSentTo('998901234567');   // digit-based
Sms::assertNothingSent();
Sms::sent();                          // Collection<SentMessage>
```

Builders, helper, named providers, facades, notifications, OTP — all route through the fake.

## Debug mode

```php
$message = Sms::to($phone)->text($t)->debug()->send();
$message->debug;   // request/response/fallback/exception trace, credentials redacted
```

Code-only (no config flag), sync-only. Bulk gets the whole-window trace per message.

## Events

- `SmsSent($message)` — exactly once per message, success or failure. Note: fires before `fallbackFrom`/`debug` are stamped.
- `DeliveryStatusUpdated(provider, providerMessageId, status, raw)` — webhooks, `updateStatus()`, `refreshStatus()`.
- `LowBalanceDetected(provider, amount, threshold)`.

## Logging

- **Database** (`SMS_LOG_DATABASE`): every send → `sms_logs` (provider, phone, text, status, segments, cost, error, payload); webhooks and `updateStatus()` update the row.
- **Debug log** (`SMS_LOG_DEBUG`): structured entry per send to `SMS_LOG_CHANNEL`, credentials redacted.

## Full `.env` reference

```dotenv
# Core
SMS_PROVIDER=eskiz               # default provider
SMS_SILENT=false                 # suppress package warning logs
SMS_WEBHOOK_ENABLED=false        # delivery-report endpoint
SMS_WEBHOOK_PATH=sms/webhooks
SMS_LOG_DATABASE=false           # sms_logs rows
SMS_LOG_DEBUG=false              # structured log entries
SMS_LOG_CHANNEL=                 # empty = default channel
SMS_LOG_PRUNE_DAYS=              # sms:prune retention
SMS_CACHE_STORE=                 # tokens/breaker/otp/dedupe store (empty = default)
SMS_CACHE_PREFIX=sms
SMS_FAKE=false
SMS_FAKE_SUCCESS_RATE=1.0
SMS_TEMPLATES_ENFORCE=false
SMS_OTP_LENGTH=6
SMS_OTP_TTL=300
SMS_OTP_MAX_ATTEMPTS=5
SMS_OTP_RESEND_COOLDOWN=60
SMS_OTP_TEMPLATE="Tasdiqlash kodi: :code"

# Eskiz — https://notify.eskiz.uz
ESKIZ_ENABLED=true
ESKIZ_EMAIL=account@example.com
ESKIZ_PASSWORD=secret
ESKIZ_FROM=4546
ESKIZ_TOKEN_TTL=2592000          # Eskiz JWTs live ~30 days
ESKIZ_FALLBACK=                  # another providers key
ESKIZ_LOW_BALANCE_THRESHOLD=     # UZS; fires LowBalanceDetected
ESKIZ_CALLBACK_ENABLED=false     # attach callback_url to sends
ESKIZ_CALLBACK_URL=              # empty = package webhook URL
ESKIZ_WEBHOOK_SECRET=            # token for incoming callbacks
ESKIZ_ALLOWED_PREFIXES=          # comma-separated; empty = all
ESKIZ_BLOCKED_PREFIXES=          # wins over allowed

# PlayMobile / smsxabar
PLAYMOBILE_ENABLED=true
PLAYMOBILE_USERNAME=login
PLAYMOBILE_PASSWORD=secret
PLAYMOBILE_FROM=3700
PLAYMOBILE_FALLBACK=
PLAYMOBILE_WEBHOOK_SECRET=       # part of the callback URL
PLAYMOBILE_ALLOWED_PREFIXES=
PLAYMOBILE_BLOCKED_PREFIXES=

# TextUp — https://textup.uz
TEXTUP_ENABLED=true
TEXTUP_EMAIL=account@example.com
TEXTUP_PASSWORD=secret
TEXTUP_NICKNAME_ID=              # empty = short number
TEXTUP_TOKEN_TTL=86400
TEXTUP_IS_OTP=false
TEXTUP_FALLBACK=
TEXTUP_ALLOWED_PREFIXES=
TEXTUP_BLOCKED_PREFIXES=

# Sayqal — https://sayqal.uz
SAYQAL_ENABLED=true
SAYQAL_USERNAME=login
SAYQAL_SECRET_KEY=secret
SAYQAL_SERVICE_ID=1
SAYQAL_NICKNAME=
SAYQAL_FALLBACK=
SAYQAL_ALLOWED_PREFIXES=
SAYQAL_BLOCKED_PREFIXES=
```

Config-only (no env): `drivers`, `prefix_rules`, `config_overrides`(+`_ttl`), `templates.list`, `webhook.middleware`, per-provider `retry`, `circuit_breaker`, `bulk`, `price_per_segment`, `health_check`, `webhook_handler`, `allowed_ips`, `http_options` (raw Guzzle — Uzbek providers usually need a whitelisted static IP, so `proxy` is first-class), `otp` block, `logging.prune_after_days`.

## Deployment and cache notes

Eskiz/TextUp tokens cache with **single-flight** refresh: on 401 one process re-logs-in under an atomic lock, the rest adopt the fresh token.

- `SMS_CACHE_STORE` (empty = app default) also holds breaker/OTP/dedupe state. Locks work on `file`, `database`, `array`, `redis`, `memcached`, `dynamodb`.
- Multi-server / Vapor: use a shared store (`redis`, `database`, `dynamodb`). Per-container caches still work — each container just logs in independently.
- No lock support → refresh degrades to lock-less re-login; duplicate logins are harmless.
- `SMS_CACHE_PREFIX` namespaces every key (`sms:eskiz:token`, `sms:eskiz:breaker`, `sms:otp:*`, `sms:dedupe:*`).

## Package tests

```bash
composer test   # Pest, Http::fake() throughout — no network, no live credentials
```

## License

MIT. See [LICENSE.md](LICENSE.md).
