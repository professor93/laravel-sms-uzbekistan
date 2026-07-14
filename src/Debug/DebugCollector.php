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

    /** @var array<int, float> */
    private array $startedAt = [];

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @return array{0: mixed, 1: list<array<string, mixed>>} [callback result, captured entries]
     */
    public function capture(Closure $callback): array
    {
        $this->listen();

        $this->active = true;
        $this->entries = [];
        $this->startedAt = [];

        try {
            $result = $callback();
        } finally {
            $entries = $this->entries;
            $this->active = false;
            $this->entries = [];
            $this->startedAt = [];
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

        $this->events->listen(RequestSending::class, function (RequestSending $event): void {
            if ($this->active) {
                $this->startedAt[spl_object_id($event->request)] = microtime(true);
            }
        });

        $this->events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if (! $this->active) {
                return;
            }

            $id = spl_object_id($event->request);
            $started = $this->startedAt[$id] ?? null;
            unset($this->startedAt[$id]);

            $json = $event->response->json();

            $this->record([
                'type' => 'request',
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'request' => $this->redact((array) $event->request->data()),
                'status' => $event->response->status(),
                'response' => is_array($json) ? $this->redact($json) : $event->response->body(),
                'duration_ms' => $started !== null ? (int) round((microtime(true) - $started) * 1000) : null,
            ]);
        });

        $this->events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if (! $this->active) {
                return;
            }

            unset($this->startedAt[spl_object_id($event->request)]);

            $this->record([
                'type' => 'connection_failed',
                'method' => $event->request->method(),
                'url' => $event->request->url(),
                'error' => $event->exception->getMessage(),
            ]);
        });
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
