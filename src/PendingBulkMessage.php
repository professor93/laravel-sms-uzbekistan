<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Closure;
use Illuminate\Support\Collection;
use LogicException;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Debug\DebugCollector;

final class PendingBulkMessage
{
    private ?string $fallback = null;

    private bool $fallbackDisabled = false;

    private bool $debug = false;

    private bool $sent = false;

    /** @var Closure(SentMessage): bool|null */
    private ?Closure $fallbackWhen = null;

    /**
     * @param  iterable<\Uzbek\Sms\Data\OutboundMessage>  $messages
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly iterable $messages,
    ) {}

    /**
     * @param  Closure(SentMessage): bool|null  $when
     */
    public function useFallback(string $provider, ?Closure $when = null): self
    {
        $this->fallback = $provider;
        $this->fallbackWhen = $when;

        return $this;
    }

    public function withoutFallback(): self
    {
        $this->fallbackDisabled = true;

        return $this;
    }

    public function debug(bool $debug = true): self
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @return Collection<int, SentMessage>
     */
    public function send(): Collection
    {
        if ($this->sent) {
            throw new LogicException('Messages already sent. Build a new bulk message for each send.');
        }

        $this->sent = true;

        $fallback = $this->fallbackDisabled
            ? null
            : ($this->fallback ?? $this->driver->defaultFallback());

        if (! $this->debug) {
            return $this->driver->sendMany($this->messages, $fallback, $this->fallbackWhen);
        }

        [$results, $entries] = app(DebugCollector::class)->capture(
            fn (): Collection => $this->driver->sendMany($this->messages, $fallback, $this->fallbackWhen),
        );

        // Batch requests cover many recipients at once, so every result
        // carries the whole window trace rather than a per-message slice.
        return $results->each(function (SentMessage $message) use ($entries): void {
            $message->debug = $entries;
        });
    }
}
