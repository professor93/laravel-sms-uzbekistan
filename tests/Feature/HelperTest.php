<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Drivers\EskizDriver;
use Uzbek\Sms\Drivers\SayqalDriver;
use Uzbek\Sms\Exceptions\UnknownDriverException;

it('resolves the default driver without arguments', function () {
    expect(sms())->toBeInstanceOf(EskizDriver::class)
        ->and(sms())->toBe(app(DriverFactory::class)->default());
});

it('resolves a named driver', function () {
    expect(sms('sayqal'))->toBeInstanceOf(SayqalDriver::class);
});

it('sends through the helper', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 3, 'status' => 'waiting']),
    ]);

    $message = sms()->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->providerMessageId)->toBe('3');
});

it('throws for an unknown driver name', function () {
    sms('nexmo');
})->throws(UnknownDriverException::class);
