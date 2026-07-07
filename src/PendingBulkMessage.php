<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Closure;
use Illuminate\Support\Collection;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;

final class PendingBulkMessage
{
    private ?string $fallback = null;

    private bool $fallbackDisabled = false;

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

    /**
     * @return Collection<int, SentMessage>
     */
    public function send(): Collection
    {
        $fallback = $this->fallbackDisabled
            ? null
            : ($this->fallback ?? $this->driver->defaultFallback());

        return $this->driver->sendMany($this->messages, $fallback, $this->fallbackWhen);
    }
}
