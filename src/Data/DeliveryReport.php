<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;
use Uzbek\Sms\Enums\DeliveryStatus;

final class DeliveryReport extends Data
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $providerMessageId,
        public DeliveryStatus $status,
        public array $raw = [],
    ) {}
}
