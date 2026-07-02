<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;

function playmobile(): Driver
{
    return app(DriverFactory::class)->make('playmobile');
}

it('sends a single sms through the messages array with basic auth', function () {
    Event::fake([SmsSent::class]);

    Http::fake(['send.smsxabar.uz/broker-api/send' => Http::response()]);

    $message = playmobile()->send('+998 91 111-22-33', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->driver)->toBe('playmobile')
        ->and($message->phone)->toBe('998911112233')
        ->and($message->providerMessageId)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Basic '.base64_encode('acme:playmobile-password')
        && $request['messages'][0]['recipient'] === '998911112233'
        && $request['messages'][0]['sms']['originator'] === '3700'
        && $request['messages'][0]['sms']['content']['text'] === 'Salom');

    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('sends bulk messages in a single request with distinct message ids', function () {
    Event::fake([SmsSent::class]);

    Http::fake(['send.smsxabar.uz/broker-api/send' => Http::response()]);

    $results = playmobile()->sendMany([
        new OutboundMessage('+998901111111', 'Birinchi'),
        new OutboundMessage('+998902222222', 'Ikkinchi'),
        new OutboundMessage('+998903333333', 'Uchinchi'),
    ]);

    expect($results)->toHaveCount(3)
        ->and($results->pluck('providerMessageId')->unique())->toHaveCount(3);

    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request): bool => count($request['messages']) === 3
        && $request['messages'][1]['sms']['content']['text'] === 'Ikkinchi');

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('folds a body-level error code into a failed SentMessage', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'send.smsxabar.uz/broker-api/send' => Http::response([
            'error-code' => 202,
            'error-description' => 'Empty recipient',
        ]),
    ]);

    $message = playmobile()->send('+998901234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and($message->status)->toBe(DeliveryStatus::Failed)
        ->and($message->errorMessage)->toContain('202')
        ->and($message->raw['error-code'])->toBe(202);

    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('marks every bulk message failed when the whole request errors', function () {
    Event::fake([SmsSent::class]);

    Http::fake(['send.smsxabar.uz/broker-api/send' => Http::response(['error' => 'internal'], 500)]);

    $results = playmobile()->sendMany(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'));

    expect($results)->toHaveCount(2)
        ->and($results->every(fn ($m): bool => ! $m->successful))->toBeTrue();

    Event::assertDispatchedTimes(SmsSent::class, 2);
});
