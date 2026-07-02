<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Models\SmsLog;

it('still dispatches events but writes no rows when database logging is off', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $dispatched = [];
    Event::listen(SmsSent::class, function (SmsSent $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->message->successful)->toBeTrue()
        ->and(SmsLog::query()->count())->toBe(0);
});
