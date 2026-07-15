<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\ChecksBalance;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\LowBalanceDetected;
use Uzbek\Sms\Facades\Sms;

it('pulls the eskiz balance', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/user/get-limit' => Http::response(['status' => 'success', 'data' => ['balance' => 150000]]),
    ]);

    $driver = app(DriverFactory::class)->make('eskiz');

    expect($driver)->toBeInstanceOf(ChecksBalance::class);

    $balance = $driver->balance();

    expect($balance->amount)->toBe(150000.0)
        ->and($balance->currency)->toBe('UZS');
});

it('fires LowBalanceDetected below the configured threshold', function () {
    config()->set('sms.providers.eskiz.low_balance_threshold', 200000);

    Event::fake([LowBalanceDetected::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/user/get-limit' => Http::response(['data' => ['balance' => 150000]]),
    ]);

    app(DriverFactory::class)->make('eskiz')->balance();

    Event::assertDispatched(LowBalanceDetected::class, fn (LowBalanceDetected $event): bool => $event->provider === 'eskiz'
        && $event->amount === 150000.0
        && $event->threshold === 200000.0);
});

it('stays quiet at or above the threshold', function () {
    config()->set('sms.providers.eskiz.low_balance_threshold', 100000);

    Event::fake([LowBalanceDetected::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/user/get-limit' => Http::response(['data' => ['balance' => 150000]]),
    ]);

    app(DriverFactory::class)->make('eskiz')->balance();

    Event::assertNotDispatched(LowBalanceDetected::class);
});

it('keeps balance support detectable per driver', function () {
    expect(app(DriverFactory::class)->make('playmobile'))->not->toBeInstanceOf(ChecksBalance::class);
});

it('reports a balance from the fake', function () {
    Sms::fake();

    expect(Sms::balance()->amount)->toBeFloat();
});
