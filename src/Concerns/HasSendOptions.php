<?php

declare(strict_types=1);

namespace Uzbek\Sms\Concerns;

use Closure;
use Uzbek\Sms\Data\SentMessage;

/**
 * Fallback, debug, and single-use state shared by the two pending builders.
 * The using class must expose a `$driver` property.
 */
trait HasSendOptions
{
    private ?string $fallback = null;

    private bool $fallbackDisabled = false;

    /** @var Closure(SentMessage): bool|null */
    private ?Closure $fallbackWhen = null;

    private bool $debug = false;

    private bool $sent = false;

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

    private function effectiveFallback(): ?string
    {
        if ($this->fallbackDisabled) {
            return null;
        }

        return $this->fallback ?? $this->driver->defaultFallback();
    }
}
