<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Models\SmsLog;

function statsLog(string $provider, DeliveryStatus $status, ?float $cost = null, ?int $daysOld = null): void
{
    $log = SmsLog::query()->create([
        'provider' => $provider,
        'phone' => '998901234567',
        'text' => 'Salom',
        'status' => $status,
        'segments' => 1,
        'cost' => $cost,
    ]);

    if ($daysOld !== null) {
        SmsLog::query()->whereKey($log->getKey())->update(['created_at' => now()->subDays($daysOld)]);
    }
}

it('aggregates counts and the delivery rate', function () {
    statsLog('eskiz', DeliveryStatus::Delivered);
    statsLog('eskiz', DeliveryStatus::Delivered);
    statsLog('eskiz', DeliveryStatus::Failed);
    statsLog('eskiz', DeliveryStatus::Pending);

    $report = Sms::stats();

    expect($report->available)->toBeTrue()
        ->and($report->total)->toBe(4)
        ->and($report->byStatus['delivered'])->toBe(2)
        ->and($report->byStatus['failed'])->toBe(1)
        ->and($report->deliveryRate)->toBe(0.5);
});

it('filters by provider', function () {
    statsLog('eskiz', DeliveryStatus::Delivered);
    statsLog('playmobile', DeliveryStatus::Failed);

    expect(Sms::stats(provider: 'playmobile')->total)->toBe(1);
});

it('filters by date range', function () {
    statsLog('eskiz', DeliveryStatus::Delivered, daysOld: 100);
    statsLog('eskiz', DeliveryStatus::Delivered);

    expect(Sms::stats(from: now()->subDays(7))->total)->toBe(1);
});

it('sums cost and segments', function () {
    statsLog('eskiz', DeliveryStatus::Delivered, cost: 115.0);
    statsLog('eskiz', DeliveryStatus::Delivered, cost: 230.0);

    $report = Sms::stats();

    expect($report->totalCost)->toBe(345.0)
        ->and($report->totalSegments)->toBe(2);
});

it('degrades gracefully when the table is missing', function () {
    Schema::drop('sms_logs');

    $report = Sms::stats();

    expect($report->available)->toBeFalse()
        ->and($report->total)->toBe(0)
        ->and($report->message)->toContain('table');
});
