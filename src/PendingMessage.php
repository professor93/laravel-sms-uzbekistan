<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Bus\PendingDispatch;
use LogicException;
use Uzbek\Sms\Concerns\HasSendOptions;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Debug\DebugCollector;
use Uzbek\Sms\Jobs\SendSmsJob;

final class PendingMessage
{
    use HasSendOptions;

    private ?string $phone = null;

    private ?string $text = null;

    /** @var array<string, mixed> */
    private array $overrides = [];

    public function __construct(private readonly Driver $driver) {}

    public function to(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * @param  array<string, string|int|float>  $params
     */
    public function template(string $name, array $params = []): self
    {
        return $this->text(app(Templates\TemplateRegistry::class)->render($name, $params));
    }

    public function otp(bool $otp = true): self
    {
        $this->overrides['is_otp'] = $otp;

        return $this;
    }

    public function isOtp(bool $otp = true): self
    {
        return $this->otp($otp);
    }

    public function from(string $sender): self
    {
        $this->overrides['from'] = $sender;

        return $this;
    }

    public function nickname(string $sender): self
    {
        return $this->from($sender);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function as(array $credentials): self
    {
        $this->overrides = array_replace($this->overrides, $credentials);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function usingCredentials(array $credentials): self
    {
        return $this->as($credentials);
    }

    public function queue(?string $queue = null): PendingDispatch
    {
        $this->guardReadyToSend();
        $this->guardTemplateCompliance([(string) $this->text]);

        if ($this->debug) {
            throw new LogicException('debug() traces the live HTTP exchange and cannot be queued.');
        }

        if ($this->fallbackWhen !== null) {
            throw new LogicException('useFallback() with a Closure predicate cannot be queued.');
        }

        // Fallback resolves now so the job survives later config changes.
        $job = new SendSmsJob(
            provider: $this->driver->name(),
            phone: (string) $this->phone,
            text: (string) $this->text,
            overrides: $this->overrides,
            fallback: $this->effectiveFallback(),
            dedupeKey: $this->dedupeKey,
            dedupeTtl: $this->dedupeTtl,
        );

        $this->sent = true;

        $dispatch = dispatch($job);

        if ($queue !== null) {
            $dispatch->onQueue($queue);
        }

        return $dispatch;
    }

    public function later(DateTimeInterface|DateInterval|int $delay, ?string $queue = null): PendingDispatch
    {
        return $this->queue($queue)->delay($delay);
    }

    public function send(): SentMessage
    {
        $this->guardReadyToSend();
        $this->guardTemplateCompliance([(string) $this->text]);

        if (! $this->reserveDedupe()) {
            $this->sent = true;

            return $this->duplicateResult($this->phone, $this->text);
        }

        // Resolving the primary can throw for a disabled/unknown override
        // driver — a config error, so the builder must stay reusable.
        $primary = $this->primary();

        $this->sent = true;

        if (! $this->debug) {
            return $this->deliver($primary, null);
        }

        $collector = app(DebugCollector::class);

        [$result, $entries] = $collector->capture(fn (): SentMessage => $this->deliver($primary, $collector));

        if (! $result->successful) {
            $entries[] = ['type' => 'exception', 'provider' => $result->provider, 'phone' => $result->phone, 'message' => $result->errorMessage];
        }

        $result->debug = $entries;

        return $result;
    }

    private function deliver(Driver $primary, ?DebugCollector $collector): SentMessage
    {
        $fallback = $this->effectiveFallback();

        $result = $primary->send($this->phone, $this->text);

        if ($fallback !== null && $this->shouldFallback($result)) {
            $collector?->record(['type' => 'fallback', 'from' => $result->provider, 'to' => $fallback]);

            $retried = app(DriverFactory::class)->make($fallback)->send($this->phone, $this->text);
            $retried->fallbackFrom = $result->provider;

            return $retried;
        }

        return $result;
    }

    private function guardReadyToSend(): void
    {
        if ($this->sent) {
            throw new LogicException('Message already sent. Build a new message for each send.');
        }

        if ($this->phone === null || $this->phone === '') {
            throw new LogicException('No recipient set. Call to() before send().');
        }

        if ($this->text === null || $this->text === '') {
            throw new LogicException('No text set. Call text() before send().');
        }
    }

    private function primary(): Driver
    {
        if ($this->overrides === []) {
            return $this->driver;
        }

        return app(DriverFactory::class)->make($this->driver->name(), $this->overrides);
    }

    private function shouldFallback(SentMessage $result): bool
    {
        return $this->fallbackWhen !== null
            ? ($this->fallbackWhen)($result)
            : ! $result->successful;
    }
}
