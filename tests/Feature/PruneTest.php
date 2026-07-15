<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Models\SmsLog;

function makeLog(int $daysOld): SmsLog
{
    $log = SmsLog::query()->create([
        'provider' => 'eskiz',
        'phone' => '998901234567',
        'text' => 'Salom',
        'status' => DeliveryStatus::Delivered,
    ]);

    SmsLog::query()->whereKey($log->getKey())->update(['created_at' => now()->subDays($daysOld)]);

    return $log;
}

it('prunes rows older than the given days', function () {
    makeLog(100);
    makeLog(5);

    $this->artisan('sms:prune', ['--days' => 90])
        ->expectsOutputToContain('Pruned 1')
        ->assertExitCode(0);

    expect(SmsLog::query()->count())->toBe(1);
});

it('reads the retention from config when no option is given', function () {
    config()->set('sms.logging.prune_after_days', 30);

    makeLog(40);
    makeLog(10);

    $this->artisan('sms:prune')->assertExitCode(0);

    expect(SmsLog::query()->count())->toBe(1);
});

it('does nothing without a retention setting', function () {
    makeLog(400);

    $this->artisan('sms:prune')
        ->expectsOutputToContain('retention')
        ->assertExitCode(0);

    expect(SmsLog::query()->count())->toBe(1);
});

it('degrades gracefully when the table is missing', function () {
    Schema::drop('sms_logs');

    $this->artisan('sms:prune', ['--days' => 30])
        ->expectsOutputToContain('table')
        ->assertExitCode(0);
});
