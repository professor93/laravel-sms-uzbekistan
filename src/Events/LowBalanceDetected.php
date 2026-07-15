<?php

declare(strict_types=1);

namespace Uzbek\Sms\Events;

final class LowBalanceDetected
{
    public function __construct(
        public readonly string $provider,
        public readonly float $amount,
        public readonly float $threshold,
    ) {}
}
