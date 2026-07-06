<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Closure;
use LogicException;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;

final class PendingMessage
{
    private ?string $phone = null;

    private ?string $text = null;

    /** @var array<string, mixed> */
    private array $overrides = [];

    private ?string $fallback = null;

    /** @var Closure(SentMessage): bool|null */
    private ?Closure $fallbackWhen = null;

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

    /**
     * @param  Closure(SentMessage): bool|null  $when
     */
    public function useFallback(string $driver, ?Closure $when = null): self
    {
        $this->fallback = $driver;
        $this->fallbackWhen = $when;

        return $this;
    }

    public function send(): SentMessage
    {
        if ($this->phone === null || $this->phone === '') {
            throw new LogicException('No recipient set. Call to() before send().');
        }

        if ($this->text === null || $this->text === '') {
            throw new LogicException('No text set. Call text() before send().');
        }

        $result = $this->primary()->send($this->phone, $this->text);

        if ($this->fallback !== null && $this->shouldFallback($result)) {
            return app(DriverFactory::class)->make($this->fallback)->send($this->phone, $this->text);
        }

        return $result;
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
