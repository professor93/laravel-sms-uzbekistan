<?php

declare(strict_types=1);

namespace Uzbek\Sms\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use Uzbek\Sms\Data\SentMessage;

/**
 * Fallback, debug, dedupe, and single-use state shared by the two pending
 * builders. The using class must expose a `$driver` property.
 */
trait HasSendOptions
{
    private ?string $dedupeKey = null;

    private int $dedupeTtl = 86400;

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

    /**
     * At-most-once: key reserved before transport, so a retry inside the TTL
     * is skipped, not resent.
     */
    public function dedupe(string $key, int $ttl = 86400): self
    {
        $this->dedupeKey = $key;
        $this->dedupeTtl = $ttl;

        return $this;
    }

    private function effectiveFallback(): ?string
    {
        if ($this->fallbackDisabled) {
            return null;
        }

        return $this->fallback ?? $this->driver->defaultFallback();
    }

    /**
     * sms.templates.enforce on = every text must match a template; mismatch
     * throws before transport.
     *
     * @param  iterable<string>  $texts
     *
     * @throws \Uzbek\Sms\Exceptions\TemplateViolationException
     */
    private function guardTemplateCompliance(iterable $texts): void
    {
        if (! config('sms.templates.enforce')) {
            return;
        }

        $registry = app(\Uzbek\Sms\Templates\TemplateRegistry::class);

        foreach ($texts as $text) {
            if (! $registry->matches((string) $text)) {
                throw \Uzbek\Sms\Exceptions\TemplateViolationException::make((string) $text);
            }
        }
    }

    /**
     * True = send may proceed. Fails open: losing dedupe beats losing sends.
     */
    private function reserveDedupe(): bool
    {
        if ($this->dedupeKey === null) {
            return true;
        }

        try {
            return cache()->store(config('sms.cache.store'))->add(
                sprintf('%s:dedupe:%s', config('sms.cache.prefix', 'sms'), $this->dedupeKey),
                1,
                $this->dedupeTtl,
            );
        } catch (Throwable $e) {
            if (! config('sms.silent')) {
                Log::channel(config('sms.logging.channel'))->warning(sprintf(
                    'SMS dedupe check for key [%s] failed; sending anyway: %s',
                    $this->dedupeKey,
                    $e->getMessage(),
                ));
            }

            return true;
        }
    }

    private function duplicateResult(string $phone, string $text): SentMessage
    {
        return SentMessage::failed(
            $this->driver->name(),
            $phone,
            $text,
            sprintf('Skipped duplicate send (dedupe key [%s] already used).', $this->dedupeKey),
        );
    }
}
