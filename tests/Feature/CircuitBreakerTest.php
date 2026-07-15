<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;

function eskizSendAttempts(): int
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send'))
        ->count();
}

it('opens the circuit after the failure threshold and skips transport', function () {
    config()->set('sms.providers.eskiz.circuit_breaker', ['threshold' => 2, 'cooldown' => 60]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'down'], 500),
    ]);

    $driver = app(DriverFactory::class)->make('eskiz');

    $driver->send('+998901234567', 'A');
    $driver->send('+998901234567', 'B');

    $third = $driver->send('+998901234567', 'C');

    expect($third->successful)->toBeFalse()
        ->and($third->errorMessage)->toContain('Circuit open')
        ->and(eskizSendAttempts())->toBe(2);
});

it('resets the failure count on success', function () {
    config()->set('sms.providers.eskiz.circuit_breaker', ['threshold' => 2, 'cooldown' => 60]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::sequence()
            ->pushStatus(500)
            ->push(['id' => 1, 'status' => 'waiting'])
            ->pushStatus(500)
            ->pushStatus(500),
    ]);

    $driver = app(DriverFactory::class)->make('eskiz');

    $driver->send('+998901234567', 'A'); // fail -> 1
    $driver->send('+998901234567', 'B'); // ok   -> reset
    $driver->send('+998901234567', 'C'); // fail -> 1, still closed

    $fourth = $driver->send('+998901234567', 'D'); // reaches transport

    expect(eskizSendAttempts())->toBe(4)
        ->and($fourth->errorMessage)->not->toContain('Circuit open');
});

it('lets the fallback provider take over while the circuit is open', function () {
    config()->set('sms.providers.eskiz.circuit_breaker', ['threshold' => 1, 'cooldown' => 60]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'down'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    $factory = app(DriverFactory::class);

    $first = $factory->make('eskiz')->to('+998901234567')->text('A')->useFallback('playmobile')->send();
    $second = $factory->make('eskiz')->to('+998901234567')->text('B')->useFallback('playmobile')->send();

    expect($first->provider)->toBe('playmobile')
        ->and($second->provider)->toBe('playmobile')
        ->and($second->fallbackFrom)->toBe('eskiz')
        ->and(eskizSendAttempts())->toBe(1);
});

it('fast-fails a bulk batch while the circuit is open', function () {
    config()->set('sms.providers.eskiz.circuit_breaker', ['threshold' => 1, 'cooldown' => 60]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'down'], 500),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['id' => 7]),
    ]);

    $driver = app(DriverFactory::class)->make('eskiz');

    $driver->send('+998901234567', 'A'); // opens the circuit

    $results = $driver->sendMany(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'S'));

    expect($results)->toHaveCount(2)
        ->and($results->every(fn ($m): bool => ! $m->successful))->toBeTrue()
        ->and($results->first()->errorMessage)->toContain('Circuit open');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'send-batch'));
});
