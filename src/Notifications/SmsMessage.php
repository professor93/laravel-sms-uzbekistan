<?php

declare(strict_types=1);

namespace Uzbek\Sms\Notifications;

final class SmsMessage
{
    public string $text = '';

    public ?string $phone = null;

    public ?string $from = null;

    public ?string $provider = null;

    public bool $otp = false;

    /** null = provider default, string = explicit provider, false = disabled */
    public string|false|null $fallback = null;

    public static function create(string $text = ''): self
    {
        return (new self)->text($text);
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /** Overrides the notifiable's sms route. */
    public function to(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function from(string $sender): self
    {
        $this->from = $sender;

        return $this;
    }

    public function provider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function otp(bool $otp = true): self
    {
        $this->otp = $otp;

        return $this;
    }

    public function useFallback(string $provider): self
    {
        $this->fallback = $provider;

        return $this;
    }

    public function withoutFallback(): self
    {
        $this->fallback = false;

        return $this;
    }
}
