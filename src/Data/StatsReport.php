<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;

final class StatsReport extends Data
{
    /**
     * @param  bool  $available  false when the sms_logs table is unreachable
     * @param  array<string, int>  $byStatus  DeliveryStatus value => count
     * @param  float|null  $totalCost  null when the cost column is absent
     */
    public function __construct(
        public bool $available,
        public ?string $message,
        public int $total,
        public array $byStatus,
        public float $deliveryRate,
        public ?float $totalCost,
        public int $totalSegments,
    ) {}

    public static function unavailable(string $message): self
    {
        return new self(
            available: false,
            message: $message,
            total: 0,
            byStatus: [],
            deliveryRate: 0.0,
            totalCost: null,
            totalSegments: 0,
        );
    }
}
