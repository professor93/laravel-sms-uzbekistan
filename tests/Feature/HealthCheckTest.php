<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\HealthStatus;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Tests\Support\FakeHealthCheck;

beforeEach(function (): void {
    FakeHealthCheck::$status = null;
});

it('reports eskiz healthy when the API answers', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/user/get-limit' => Http::response(['data' => ['balance' => 500000]]),
    ]);

    $status = Sms::health('eskiz');

    expect($status->healthy)->toBeTrue();
});

it('reports eskiz unhealthy when the API errors', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/user/get-limit' => Http::response(['message' => 'down'], 500),
    ]);

    $status = Sms::health('eskiz');

    expect($status->healthy)->toBeFalse()
        ->and($status->message)->not->toBeNull();
});

it('reports unknown for a driver without any check', function () {
    expect(Sms::health('sayqal')->healthy)->toBeNull();
});

it('prefers a config-registered health check class', function () {
    FakeHealthCheck::$status = HealthStatus::failed('nope');
    config()->set('sms.providers.playmobile.health_check', FakeHealthCheck::class);

    $status = Sms::health('playmobile');

    expect($status->healthy)->toBeFalse()
        ->and($status->message)->toBe('nope');
});

it('degrades gracefully on a misconfigured check class', function () {
    config()->set('sms.providers.playmobile.health_check', stdClass::class);

    $status = Sms::health('playmobile');

    expect($status->healthy)->toBeFalse()
        ->and($status->message)->toContain('HealthCheck');
});

it('degrades gracefully for an unknown provider', function () {
    expect(Sms::health('nexmo')->healthy)->toBeFalse();
});

it('checks every configured provider at once', function () {
    Http::fake();

    $statuses = Sms::health();

    expect($statuses)->toHaveKeys(['eskiz', 'playmobile', 'textup', 'sayqal'])
        ->and($statuses['sayqal'])->toBeInstanceOf(HealthStatus::class);
});
