<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;

function sendAttempts(): int
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send'))
        ->count();
}

it('does not retry transient errors by default', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and(sendAttempts())->toBe(1);
});

it('retries server errors up to the configured attempts', function () {
    config()->set('sms.providers.eskiz.retry', ['times' => 3, 'sleep' => 0]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::sequence()
            ->pushStatus(500)
            ->pushStatus(502)
            ->push(['id' => 5, 'status' => 'waiting']),
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and(sendAttempts())->toBe(3);
});

it('gives up after the configured attempts', function () {
    config()->set('sms.providers.eskiz.retry', ['times' => 2, 'sleep' => 0]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and(sendAttempts())->toBe(2);
});

it('retries connection failures when configured', function () {
    config()->set('sms.providers.eskiz.retry', ['times' => 3, 'sleep' => 0]);

    $attempts = 0;

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['id' => 5, 'status' => 'waiting']);
        },
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($attempts)->toBe(3);
});

it('does not retry client errors', function () {
    config()->set('sms.providers.eskiz.retry', ['times' => 3, 'sleep' => 0]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'bad request'], 422),
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and(sendAttempts())->toBe(1);
});
