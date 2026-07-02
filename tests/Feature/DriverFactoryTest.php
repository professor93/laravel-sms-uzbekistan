<?php

declare(strict_types=1);

use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Drivers\EskizDriver;
use Uzbek\Sms\Drivers\SayqalDriver;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Exceptions\UnknownDriverException;

it('throws for an unmapped driver name', function () {
    app(DriverFactory::class)->make('nexmo');
})->throws(UnknownDriverException::class, 'SMS driver [nexmo] is not defined');

it('throws for a disabled driver with an actionable message', function () {
    config()->set('sms.drivers.eskiz.enabled', false);

    app(DriverFactory::class)->make('eskiz');
})->throws(DriverDisabledException::class, 'Enable it via ESKIZ_ENABLED');

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
    config()->set('sms.drivers.eskiz.enabled', false);

    app(Driver::class);
})->throws(DriverDisabledException::class);

it('memoizes resolved drivers per name', function () {
    $factory = app(DriverFactory::class);

    expect($factory->make('eskiz'))->toBe($factory->make('eskiz'));
});
