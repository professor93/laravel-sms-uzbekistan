<?php

declare(strict_types=1);

namespace Uzbek\Sms\Events;

use Uzbek\Sms\Enums\DeliveryStatus;

final class DeliveryStatusUpdated
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $providerMessageId,
        public readonly DeliveryStatus $status,
        public readonly array $raw = [],
    ) {}
}
