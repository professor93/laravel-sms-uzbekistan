<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\SupportsBulkFallback;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Exceptions\ProhibitedPhoneException;
use Uzbek\Sms\PendingBulkMessage;
use Uzbek\Sms\PendingMessage;

abstract class AbstractDriver implements Driver
{
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
        try {
            $this->guardPhone($phone);

            $message = $this->doSend($phone, $text);
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

        $sent = $sendable->isEmpty() ? new Collection : $this->doSendMany($sendable->values());

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

        $fallbackResults = app(DriverFactory::class)->make($fallback)->sendMany($retryMessages);

        $merged = $primary->all();

        $retryKeys->each(function (int $originalIndex, int $position) use (&$merged, $fallbackResults): void {
            $merged[$originalIndex] = $fallbackResults->get($position);
        });

        return Collection::make($merged)->values();
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
            (array) ($this->config['prefixes'][$list] ?? []),
        );

        return array_values(array_filter($prefixes, fn (string $prefix): bool => $prefix !== ''));
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

        // Nullable: Laravel passes null for non-2xx, non-failed (3xx) responses.
        return $request->retry(2, 200, function (?Throwable $e, PendingRequest $request): bool {
            if (! $e instanceof RequestException || $e->response->status() !== 401) {
                return false;
            }

            $this->auth->refresh();
            // Mutate the same request so the retry carries the fresh token.
            $this->auth->apply($request);

            return true;
        });
    }
}
