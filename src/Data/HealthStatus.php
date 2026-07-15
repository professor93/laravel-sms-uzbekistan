<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;

final class HealthStatus extends Data
{
    /**
     * @param  bool|null  $healthy  null = no health check available for the provider
     */
    public function __construct(
        public ?bool $healthy,
        public ?string $message = null,
    ) {}

    public static function ok(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    public static function unknown(): self
    {
        return new self(null, 'No health check configured.');
    }
}
