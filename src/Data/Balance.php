<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;

final class Balance extends Data
{
    /**
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public float $amount,
        public ?string $currency = null,
        public array $raw = [],
    ) {}
}
