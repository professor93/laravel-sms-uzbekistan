<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Drivers\EskizDriver;
use Uzbek\Sms\Drivers\SayqalDriver;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Exceptions\UnknownProviderException;

it('throws for an unknown provider name', function () {
    app(DriverFactory::class)->make('nexmo');
})->throws(UnknownProviderException::class, 'SMS provider [nexmo] is not defined');

it('throws for a disabled provider with an actionable message', function () {
    config()->set('sms.providers.eskiz.enabled', false);

    app(DriverFactory::class)->make('eskiz');
})->throws(DriverDisabledException::class, 'Enable it via sms.providers.eskiz.enabled');

it('resolves the configured default driver', function () {
    expect(app(DriverFactory::class)->default())->toBeInstanceOf(EskizDriver::class);
});

it('binds the Driver contract to the default driver', function () {
    expect(app(Driver::class))->toBeInstanceOf(EskizDriver::class);
});

it('switches the default driver through config', function () {
    config()->set('sms.default', 'sayqal');

    expect(app(Driver::class))->toBeInstanceOf(SayqalDriver::class);
});

it('fails fast when the default driver is disabled', function () {
    config()->set('sms.providers.eskiz.enabled', false);

    app(Driver::class);
})->throws(DriverDisabledException::class);

it('memoizes resolved drivers per name', function () {
    $factory = app(DriverFactory::class);

    expect($factory->make('eskiz'))->toBe($factory->make('eskiz'));
});

it('resolves a provider to the driver named by its driver key', function () {
    config()->set('sms.providers.marketing', [
        'driver' => 'eskiz',
        'enabled' => true,
        'base_url' => 'https://notify.eskiz.uz/api',
        'email' => 'mkt@example.test',
        'password' => 'mkt-pass',
        'from' => '4546',
        'token_ttl' => 3600,
        'http_options' => [],
    ]);

    $driver = app(DriverFactory::class)->make('marketing');

    expect($driver)->toBeInstanceOf(EskizDriver::class)
        ->and($driver->name())->toBe('marketing');
});

it('isolates cached tokens per provider name for the same driver', function () {
    config()->set('sms.providers.marketing', [
        'driver' => 'eskiz',
        'enabled' => true,
        'base_url' => 'https://notify.eskiz.uz/api',
        'email' => 'mkt@example.test',
        'password' => 'mkt-pass',
        'from' => '4546',
        'token_ttl' => 3600,
        'http_options' => [],
    ]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::sequence()
            ->push(['data' => ['token' => 'token-eskiz']])
            ->push(['data' => ['token' => 'token-marketing']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $factory = app(DriverFactory::class);
    $factory->make('eskiz')->send('+998901111111', 'A');
    $factory->make('marketing')->send('+998902222222', 'B');

    expect(Cache::get('sms:eskiz:token'))->toBe('token-eskiz')
        ->and(Cache::get('sms:marketing:token'))->toBe('token-marketing');
});
