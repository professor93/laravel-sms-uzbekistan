<?php

declare(strict_types=1);

namespace Uzbek\Sms\Debug;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Captures every HTTP exchange (send, auth, fallback) that happens inside a
 * debug-enabled send window. PHP's synchronous execution guarantees only the
 * send's own traffic lands between capture start and end.
 */
final class DebugCollector
{
    private const REDACTED = '••••••';

    private const SENSITIVE_KEYS = ['password', 'secret_key', 'token', 'accesstoken', 'authorization'];

    private bool $active = false;

    private bool $listening = false;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    private ?float $requestStartedAt = null;

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @return array{0: mixed, 1: list<array<string, mixed>>} [callback result, captured entries]
     */
    public function capture(Closure $callback): array
    {
        // A capture inside a capture (an SmsSent listener sending its own
        // debug message) must not clobber the outer window: the inner send
        // gets no trace of its own and its traffic lands in the outer trace.
        if ($this->active) {
            return [$callback(), []];
        }

        $this->listen();

        $this->active = true;
        $this->entries = [];
        $this->requestStartedAt = null;

        try {
            $result = $callback();
        } finally {
            $entries = $this->entries;
            $this->active = false;
            $this->entries = [];
            $this->requestStartedAt = null;
        }

        return [$result, $entries];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        if ($this->active) {
            $this->entries[] = $entry;
        }
    }

    // The dispatcher cannot detach a single listener, so listeners register
    // once and stay no-ops outside an active capture window.
    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        // Execution inside a window is synchronous, so at most one request is
        // ever in flight — a single timestamp is enough for duration_ms.
        $this->events->listen(RequestSending::class, function (RequestSending $event): void {
            if ($this->active) {
                $this->requestStartedAt = microtime(true);
            }
        });

        $this->events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if (! $this->active) {
                return;
            }

            $started = $this->requestStartedAt;
            $this->requestStartedAt = null;

            $request = $this->redact((array) $event->request->data());
            $json = $event->response->json();

            $this->record([
                'type' => 'request',
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'request' => $request,
                'status' => $event->response->status(),
                'response' => $this->redactedResponse($json, $event->response->body(), $request),
                'duration_ms' => $started !== null ? (int) round((microtime(true) - $started) * 1000) : null,
            ]);
        });

        $this->events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if (! $this->active) {
                return;
            }

            $this->requestStartedAt = null;

            $this->record([
                'type' => 'connection_failed',
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'error' => $event->exception->getMessage(),
            ]);
        });
    }

    /**
     * A non-array body can't be key-redacted; when the request carried
     * credentials (a login call) the body is likely a raw token, so blank it
     * whole rather than leak it. `$request` is already redacted, so sensitive
     * keys are detected by their masked value.
     *
     * @param  array<array-key, mixed>  $request
     */
    private function redactedResponse(mixed $json, string $body, array $request): mixed
    {
        if (is_array($json)) {
            return $this->redact($json);
        }

        return $this->containsRedactedValue($request) ? self::REDACTED : $body;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function containsRedactedValue(array $data): bool
    {
        foreach ($data as $value) {
            if ($value === self::REDACTED || (is_array($value) && $this->containsRedactedValue($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
