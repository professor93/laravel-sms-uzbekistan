<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Models\SmsLog;

function fakeEskizSend(): void
{
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);
}

it('has no cost without a configured price', function () {
    $message = SentMessage::success('eskiz', '998901234567', 'Salom', '1');

    expect($message->cost())->toBeNull();
});

it('computes cost as segments times price', function () {
    config()->set('sms.providers.eskiz.price_per_segment', 115.0);

    $twoSegments = SentMessage::success('eskiz', '998901234567', str_repeat('б', 71), '1');

    expect($twoSegments->cost())->toBe(230.0);
});

it('persists segments and cost with the log row', function () {
    config()->set('sms.providers.eskiz.price_per_segment', 115.0);

    fakeEskizSend();

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    $log = SmsLog::query()->sole();

    expect($log->segments)->toBe(1)
        ->and($log->cost)->toBe(115.0);
});

it('persists null cost when no price is configured', function () {
    fakeEskizSend();

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    $log = SmsLog::query()->sole();

    expect($log->segments)->toBe(1)
        ->and($log->cost)->toBeNull();
});

it('keeps persisting when the cost columns are missing', function () {
    Schema::table('sms_logs', function ($table): void {
        $table->dropColumn(['segments', 'cost']);
    });

    fakeEskizSend();

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect(SmsLog::query()->count())->toBe(1);
});
