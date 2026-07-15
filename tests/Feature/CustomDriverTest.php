<?php

declare(strict_types=1);

use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Exceptions\UnknownDriverException;
use Uzbek\Sms\Tests\Support\FakeDriver;

it('resolves a provider whose driver is a custom class-string', function () {
    config()->set('sms.providers.custom', [
        'driver' => FakeDriver::class,
        'enabled' => true,
        'base_url' => 'https://example.test',
        'http_options' => [],
    ]);

    $driver = app(DriverFactory::class)->make('custom');

    expect($driver)->toBeInstanceOf(FakeDriver::class)
        ->and($driver->name())->toBe('custom');

    $message = $driver->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->provider)->toBe('custom');
});

it('throws when a driver value is neither a built-in name nor a driver class', function () {
    config()->set('sms.providers.broken', ['driver' => 'nope', 'enabled' => true]);

    app(DriverFactory::class)->make('broken');
})->throws(UnknownDriverException::class, 'SMS driver [nope] is not defined');

it('resolves a driver alias registered in the sms.drivers config map', function () {
    config()->set('sms.drivers', ['fake' => FakeDriver::class]);
    config()->set('sms.providers.custom', [
        'driver' => 'fake',
        'enabled' => true,
        'base_url' => 'https://example.test',
        'http_options' => [],
    ]);

    $driver = app(DriverFactory::class)->make('custom');

    expect($driver)->toBeInstanceOf(FakeDriver::class)
        ->and($driver->name())->toBe('custom');
});

it('lets the sms.drivers config map override a built-in alias', function () {
    config()->set('sms.drivers', ['eskiz' => FakeDriver::class]);

    expect(app(DriverFactory::class)->make('eskiz'))->toBeInstanceOf(FakeDriver::class);
});

it('throws when a mapped driver class is not an AbstractDriver subclass', function () {
    config()->set('sms.drivers', ['bogus' => stdClass::class]);
    config()->set('sms.providers.custom', ['driver' => 'bogus', 'enabled' => true]);

    app(DriverFactory::class)->make('custom');
})->throws(UnknownDriverException::class);
