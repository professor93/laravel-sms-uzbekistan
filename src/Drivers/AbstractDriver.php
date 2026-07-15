<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\PrefixRules;
use Uzbek\Sms\Contracts\SupportsBulkFallback;
use Uzbek\Sms\Data\Balance;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Debug\DebugCollector;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\LowBalanceDetected;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Exceptions\ProhibitedPhoneException;
use Uzbek\Sms\Exceptions\SmsException;
use Uzbek\Sms\PendingBulkMessage;
use Uzbek\Sms\PendingMessage;

abstract class AbstractDriver implements Driver
{
    /** @var array{allowed: list<string>, blocked: list<string>} */
    private array $dynamicPrefixes = ['allowed' => [], 'blocked' => []];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly Authenticator $auth,
        protected readonly HttpFactory $http,
        protected readonly array $config,
        protected readonly CacheRepository $cache,
    ) {}

    final public function send(string $phone, string $text): SentMessage
    {
        $this->loadDynamicPrefixes();

        try {
            $this->guardPhone($phone);

            $message = $this->faking()
                ? $this->fakeResult($phone, $text, $this->fakeSuccessRate(), app(DebugCollector::class))
                : $this->doSend($phone, $text);
        } catch (Throwable $e) {
            $message = SentMessage::failed($this->name(), $phone, $text, $e->getMessage());
        }

        $this->dispatchSent($message);

        return $message;
    }

    /**
     * @param  iterable<OutboundMessage>  $messages
     * @param  Closure(SentMessage): bool|null  $fallbackWhen
     * @return Collection<int, SentMessage>
     */
    final public function sendMany(iterable $messages, ?string $fallback = null, ?Closure $fallbackWhen = null): Collection
    {
        $this->loadDynamicPrefixes();

        $messages = Collection::make($messages)->values();

        $rejected = [];

        $sendable = $messages->filter(function (OutboundMessage $message, int $index) use (&$rejected): bool {
            try {
                $this->guardPhone($message->phone);

                return true;
            } catch (ProhibitedPhoneException $e) {
                $rejected[$index] = SentMessage::failed($this->name(), $message->phone, $message->text, $e->getMessage());

                return false;
            }
        });

        foreach ($rejected as $failure) {
            $this->dispatchSent($failure);
        }

        $sent = match (true) {
            $sendable->isEmpty() => new Collection,
            $this->faking() => $this->fakeSendMany($sendable->values()),
            default => $this->doSendMany($sendable->values()),
        };

        if ($rejected === []) {
            $primary = $sent;
        } else {
            $primary = new Collection;
            $next = 0;

            foreach ($messages->keys() as $index) {
                $primary->push($rejected[$index] ?? $sent->get($next++));
            }
        }

        return $this->applyBulkFallback($messages, $primary, $fallback, $fallbackWhen);
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @param  Collection<int, SentMessage>  $primary
     * @param  Closure(SentMessage): bool|null  $fallbackWhen
     * @return Collection<int, SentMessage>
     */
    private function applyBulkFallback(Collection $messages, Collection $primary, ?string $fallback, ?Closure $fallbackWhen): Collection
    {
        if ($fallback === null || $fallback === '') {
            return $primary;
        }

        $predicate = $fallbackWhen ?? fn (SentMessage $message): bool => ! $message->successful;

        $retryKeys = $primary->keys()->filter(fn (int $index): bool => $predicate($primary->get($index)))->values();

        if ($retryKeys->isEmpty()) {
            return $primary;
        }

        if (! $this instanceof SupportsBulkFallback) {
            $this->warnUnsupportedBulkFallback($fallback);

            return $primary;
        }

        $retryMessages = $retryKeys->map(fn (int $index): OutboundMessage => $messages->get($index))->values();

        app(DebugCollector::class)->record([
            'type' => 'fallback',
            'from' => $this->name(),
            'to' => $fallback,
            'messages' => $retryKeys->count(),
        ]);

        $fallbackResults = app(DriverFactory::class)->make($fallback)->sendMany($retryMessages);

        $merged = $primary->all();

        $retryKeys->each(function (int $originalIndex, int $position) use (&$merged, $fallbackResults): void {
            $retried = $fallbackResults->get($position);

            // A custom fallback driver may return fewer results than asked —
            // keep the primary failure rather than dereferencing null.
            if ($retried instanceof SentMessage) {
                $retried->fallbackFrom = $this->name();

                $merged[$originalIndex] = $retried;
            }
        });

        return Collection::make($merged)->values();
    }

    private function faking(): bool
    {
        return (bool) config('sms.fake.enabled');
    }

    // Blank counts as unset; anything else outside 0..1 (e.g. "90" meant as
    // a percentage) is a misconfiguration — warn and use the default.
    private function fakeSuccessRate(): float
    {
        $rate = config('sms.fake.success_rate', 1.0);

        if ($rate === null || $rate === '') {
            return 1.0;
        }

        if (! is_numeric($rate) || (float) $rate < 0.0 || (float) $rate > 1.0) {
            if (! config('sms.silent')) {
                Log::channel(config('sms.logging.channel'))->warning(sprintf(
                    'Invalid sms.fake.success_rate [%s]; expected a probability between 0 and 1, using 1.0.',
                    is_scalar($rate) ? (string) $rate : gettype($rate),
                ));
            }

            return 1.0;
        }

        return (float) $rate;
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    private function fakeSendMany(Collection $messages): Collection
    {
        $rate = $this->fakeSuccessRate();
        $collector = app(DebugCollector::class);

        return $messages->map(function (OutboundMessage $message) use ($rate, $collector): SentMessage {
            $result = $this->fakeResult($message->phone, $message->text, $rate, $collector);

            $this->dispatchSent($result);

            return $result;
        });
    }

    private function fakeResult(string $phone, string $text, float $rate, DebugCollector $collector): SentMessage
    {
        $successful = $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;

        $collector->record([
            'type' => 'fake',
            'provider' => $this->name(),
            'phone' => $phone,
            'success_rate' => $rate,
            'successful' => $successful,
        ]);

        if ($successful) {
            return SentMessage::success(
                provider: $this->name(),
                phone: $phone,
                text: $text,
                providerMessageId: 'fake-'.Str::ulid(),
                raw: ['fake' => true],
            );
        }

        return SentMessage::failed($this->name(), $phone, $text, 'Simulated failure (fake mode).', ['fake' => true]);
    }

    private function warnUnsupportedBulkFallback(string $fallback): void
    {
        if (config('sms.silent')) {
            return;
        }

        Log::channel(config('sms.logging.channel'))->warning(sprintf(
            'SMS provider [%s] does not support bulk fallback; returning primary results (requested fallback [%s]).',
            $this->name(),
            $fallback,
        ));
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    protected function doSendMany(Collection $messages): Collection
    {
        return $messages->map(fn (OutboundMessage $message): SentMessage => $this->send($message->phone, $message->text));
    }

    public function to(string $phone): PendingMessage
    {
        return (new PendingMessage($this))->to($phone);
    }

    public function many(iterable $messages): PendingBulkMessage
    {
        return new PendingBulkMessage($this, $messages);
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? '');
    }

    public function defaultFallback(): ?string
    {
        $fallback = $this->config['fallback'] ?? null;

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * Fires LowBalanceDetected when the amount falls below the provider's
     * low_balance_threshold config. Drivers wrap their balance() result.
     */
    protected function reportBalance(Balance $balance): Balance
    {
        $threshold = $this->config['low_balance_threshold'] ?? null;

        if (is_numeric($threshold) && $balance->amount < (float) $threshold) {
            Event::dispatch(new LowBalanceDetected($this->name(), $balance->amount, (float) $threshold));
        }

        return $balance;
    }

    /**
     * Delivery-callback URL for outbound payloads: null (omit) unless the
     * provider opts in with callback_enabled; an explicit callback_url wins
     * over the package webhook route.
     */
    protected function callbackUrl(): ?string
    {
        if (! ($this->config['callback_enabled'] ?? false)) {
            return null;
        }

        $url = $this->config['callback_url'] ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return $this->webhookUrl();
    }

    private function webhookUrl(): ?string
    {
        if (! config('sms.webhook.enabled')) {
            return null;
        }

        $url = route('sms.webhook', ['provider' => $this->name()]);

        $secret = (string) ($this->config['webhook_secret'] ?? '');

        return $secret === '' ? $url : $url.'?token='.urlencode($secret);
    }

    abstract protected function doSend(string $phone, string $text): SentMessage;

    /**
     * @param  array<string, mixed>  $config
     */
    abstract public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator;

    /**
     * Fires SmsSent exactly once per bulk message.
     *
     * @param  Collection<int, SentMessage>  $results
     * @return Collection<int, SentMessage>
     */
    protected function finalizeBulk(Collection $results): Collection
    {
        return $results->each(fn (SentMessage $message) => $this->dispatchSent($message));
    }

    private function dispatchSent(SentMessage $message): void
    {
        try {
            Event::dispatch(new SmsSent($message));
        } catch (Throwable $e) {
            // The SMS is already out — a listener failure must not throw.
            report($e);
        }
    }

    /**
     * @throws ProhibitedPhoneException
     */
    protected function guardPhone(string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        foreach ($this->prefixes('blocked') as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                throw ProhibitedPhoneException::blocked($phone, $prefix);
            }
        }

        $allowed = $this->prefixes('allowed');

        if ($allowed === []) {
            return;
        }

        foreach ($allowed as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return;
            }
        }

        throw ProhibitedPhoneException::notAllowed($phone);
    }

    /**
     * @return list<string>
     */
    private function prefixes(string $list): array
    {
        $prefixes = array_map(
            fn (mixed $prefix): string => preg_replace('/\D+/', '', (string) $prefix) ?? '',
            array_merge((array) ($this->config['prefixes'][$list] ?? []), $this->dynamicPrefixes[$list]),
        );

        return array_values(array_unique(array_filter($prefixes, fn (string $prefix): bool => $prefix !== '')));
    }

    /**
     * Merges in lists from the configured PrefixRules class (per-provider
     * `prefix_rules` key, else the global `sms.prefix_rules`). Runs once per
     * send call so rule changes apply without rebuilding the driver. Fails
     * open to the static config lists — an unavailable rules source must not
     * take messaging down, so keep hard legal blocks in the config.
     */
    private function loadDynamicPrefixes(): void
    {
        $this->dynamicPrefixes = ['allowed' => [], 'blocked' => []];

        $class = $this->config['prefix_rules'] ?? config('sms.prefix_rules');

        if (! is_string($class) || $class === '') {
            return;
        }

        try {
            $rules = app($class);

            if (! $rules instanceof PrefixRules) {
                throw new SmsException(sprintf('[%s] does not implement [%s].', $class, PrefixRules::class));
            }

            $this->dynamicPrefixes = [
                'allowed' => array_values($rules->allowlist($this->name())),
                'blocked' => array_values($rules->blocklist($this->name())),
            ];
        } catch (Throwable $e) {
            if (! config('sms.silent')) {
                Log::channel(config('sms.logging.channel'))->warning(sprintf(
                    'SMS prefix rules [%s] failed for provider [%s]; using the static config lists only: %s',
                    $class,
                    $this->name(),
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    protected function failAll(Collection $messages, Throwable $e): Collection
    {
        return $messages->map(fn (OutboundMessage $message): SentMessage => SentMessage::failed(
            $this->name(),
            $message->phone,
            $message->text,
            $e->getMessage(),
        ));
    }

    protected function http(): PendingRequest
    {
        $request = $this->http
            ->baseUrl((string) $this->config['base_url'])
            ->asJson()
            ->acceptJson()
            ->withOptions($this->config['http_options'] ?? []);

        $request = $this->auth->apply($request);

        // Opt-in transient retries; without the config the behavior stays
        // exactly as before (2 attempts, 401-refresh only).
        $transientAttempts = (int) ($this->config['retry']['times'] ?? 0);

        $attempts = max(2, $transientAttempts);
        $sleep = (int) ($this->config['retry']['sleep'] ?? 200);

        // Nullable: Laravel passes null for non-2xx, non-failed (3xx) responses.
        return $request->retry($attempts, $sleep, function (?Throwable $e, PendingRequest $request) use ($transientAttempts): bool {
            if ($e instanceof RequestException && $e->response->status() === 401) {
                $this->auth->refresh();
                // Mutate the same request so the retry carries the fresh token.
                $this->auth->apply($request);

                return true;
            }

            if ($transientAttempts < 2) {
                return false;
            }

            return $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError());
        });
    }
}
