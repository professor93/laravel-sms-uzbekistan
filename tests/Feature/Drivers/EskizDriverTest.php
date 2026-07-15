<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;

function eskiz(): Driver
{
    return app(DriverFactory::class)->make('eskiz');
}

it('sends a single sms and dispatches SmsSent once', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 4385062, 'status' => 'waiting']),
    ]);

    $message = eskiz()->send('+998 90 123-45-67', 'Salom dunyo');

    expect($message->successful)->toBeTrue()
        ->and($message->provider)->toBe('eskiz')
        ->and($message->phone)->toBe('998901234567')
        ->and($message->providerMessageId)->toBe('4385062')
        ->and($message->status)->toBe(DeliveryStatus::Pending);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['mobile_phone'] === '998901234567'
        && $request['message'] === 'Salom dunyo'
        && $request['from'] === '4546'
        && $request->header('Authorization')[0] === 'Bearer jwt-1');

    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('converts transport failures into a failed SentMessage without throwing', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    $message = eskiz()->send('+998901234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and($message->status)->toBe(DeliveryStatus::Failed)
        ->and($message->errorMessage)->not->toBeNull();

    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('sends a batch in one request with distinct client-generated ids', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['id' => 77, 'status' => ['success' => 3]]),
    ]);

    $results = eskiz()->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333'],
        'Bir xil matn',
    ));

    expect($results)->toHaveCount(3)
        ->and($results->every(fn ($m): bool => $m->successful))->toBeTrue()
        ->and($results->pluck('providerMessageId')->unique())->toHaveCount(3);

    Http::assertSentCount(2); // login + one batch request

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'send-batch')) {
            return true;
        }

        return count($request['messages']) === 3
            && $request['messages'][0]['to'] === '998901111111';
    });

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('supports per-recipient texts through the batch endpoint', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['id' => 78]),
    ]);

    $results = eskiz()->sendMany([
        new OutboundMessage('+998901111111', 'Birinchi'),
        new OutboundMessage('+998902222222', 'Ikkinchi'),
    ]);

    expect($results)->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'send-batch')) {
            return true;
        }

        return $request['messages'][0]['text'] === 'Birinchi'
            && $request['messages'][1]['text'] === 'Ikkinchi';
    });
});

it('refreshes the token once on 401 and retries with the fresh token', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::sequence()
            ->push(['data' => ['token' => 'stale-token']])
            ->push(['data' => ['token' => 'fresh-token']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::sequence()
            ->pushStatus(401)
            ->push(['id' => 99, 'status' => 'waiting']),
    ]);

    $message = eskiz()->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->providerMessageId)->toBe('99');

    $sends = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send'));

    expect($sends)->toHaveCount(2)
        ->and($sends->first()->header('Authorization')[0])->toBe('Bearer stale-token')
        ->and($sends->last()->header('Authorization')[0])->toBe('Bearer fresh-token');
});

it('handles a redirect response without a retry-callback type error', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(null, 302),
    ]);

    $message = eskiz()->send('+998901234567', 'Salom');

    expect($message)->toBeInstanceOf(\Uzbek\Sms\Data\SentMessage::class);
});

it('omits callback_url when callback sending is disabled even if a url is configured', function () {
    config()->set('sms.providers.eskiz.callback_url', 'https://app.example.test/sms/callback');

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    eskiz()->send('+998901234567', 'Salom');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && array_key_exists('callback_url', $request->data()));
});

it('sends the explicit callback_url when callback sending is enabled', function () {
    config()->set('sms.providers.eskiz.callback_enabled', true);
    config()->set('sms.providers.eskiz.callback_url', 'https://app.example.test/sms/callback');

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    eskiz()->send('+998901234567', 'Salom');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['callback_url'] === 'https://app.example.test/sms/callback');
});

it('falls back to the package webhook url with token when no explicit callback_url is set', function () {
    config()->set('sms.providers.eskiz.callback_enabled', true);
    config()->set('sms.providers.eskiz.webhook_secret', 'esk-secret');

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    eskiz()->send('+998901234567', 'Salom');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['callback_url'] === 'http://localhost/sms/webhooks/eskiz?token=esk-secret');
});

it('omits callback_url when webhook routing is disabled and no explicit url is set', function () {
    config()->set('sms.webhook.enabled', false);
    config()->set('sms.providers.eskiz.callback_enabled', true);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    eskiz()->send('+998901234567', 'Salom');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && array_key_exists('callback_url', $request->data()));
});

it('includes callback_url in batch sends when callback sending is enabled', function () {
    config()->set('sms.providers.eskiz.callback_enabled', true);
    config()->set('sms.providers.eskiz.callback_url', 'https://app.example.test/sms/callback');

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['id' => 77]),
    ]);

    eskiz()->sendMany(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'send-batch')
        && $request['callback_url'] === 'https://app.example.test/sms/callback');
});

it('pulls delivery status by provider message id', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/status_by_id/*' => Http::response(['status' => 'success', 'data' => ['status' => 'DELIVERED']]),
    ]);

    $driver = eskiz();

    expect($driver)->toBeInstanceOf(ChecksDeliveryStatus::class)
        ->and($driver->checkStatus('4385062'))->toBe(DeliveryStatus::Delivered);
});
