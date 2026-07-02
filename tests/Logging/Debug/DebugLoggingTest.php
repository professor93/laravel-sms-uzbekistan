<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Uzbek\Sms\DriverFactory;

it('writes a structured entry to the configured channel without credentials', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'top-secret-jwt']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 777, 'status' => 'waiting']),
    ]);

    $entries = [];
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context) use (&$entries): void {
        $entries[] = compact('level', 'message', 'context');
    });

    Log::partialMock()->shouldReceive('channel')->with('sms-debug')->andReturn($channel);

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['level'])->toBe('info')
        ->and($entries[0]['context']['driver'])->toBe('eskiz')
        ->and($entries[0]['context']['phone'])->toBe('998901234567')
        ->and($entries[0]['context']['provider_message_id'])->toBe('777')
        ->and($entries[0]['context']['successful'])->toBeTrue();

    $serialized = json_encode($entries);

    expect($serialized)->not->toContain('top-secret-jwt')
        ->and($serialized)->not->toContain('eskiz-password')
        ->and($serialized)->not->toContain('Authorization');
});

it('logs failed sends at warning level', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'top-secret-jwt']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    $entries = [];
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context) use (&$entries): void {
        $entries[] = compact('level', 'message', 'context');
    });

    Log::partialMock()->shouldReceive('channel')->with('sms-debug')->andReturn($channel);

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context']['successful'])->toBeFalse()
        ->and($entries[0]['context']['error'])->not->toBeNull();
});
