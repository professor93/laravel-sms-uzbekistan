<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Models\SmsLog;

it('rejects a blocked prefix without contacting the provider', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['blocked' => ['99897']]);

    Http::fake();

    $message = app(DriverFactory::class)->make('eskiz')->send('+998 97 123-45-67', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and($message->status)->toBe(DeliveryStatus::Failed)
        ->and($message->errorMessage)->toContain('blocked prefix [99897]');

    Http::assertNothingSent();
});

it('rejects numbers outside the allowed list', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['allowed' => ['99890', '99891']]);

    Http::fake();

    $message = app(DriverFactory::class)->make('eskiz')->send('+998331234567', 'Salom');

    expect($message->successful)->toBeFalse()
        ->and($message->errorMessage)->toContain('does not match any allowed prefix');

    Http::assertNothingSent();
});

it('sends numbers matching an allowed prefix', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['allowed' => ['99890']]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue();
});

it('applies rules per driver — another driver stays open', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['blocked' => ['99897']]);

    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 1])]);

    $message = app(DriverFactory::class)->make('sayqal')->send('+998971234567', 'Salom');

    expect($message->successful)->toBeTrue();
});

it('skips blocked recipients in a native bulk without aborting the rest', function () {
    config()->set('sms.drivers.playmobile.prefixes', ['blocked' => ['99897']]);

    Event::fake([SmsSent::class]);

    Http::fake(['send.smsxabar.uz/broker-api/send' => Http::response()]);

    $results = app(DriverFactory::class)->make('playmobile')->sendMany([
        new OutboundMessage('+998901111111', 'Salom'),
        new OutboundMessage('+998971111111', 'Salom'),
        new OutboundMessage('+998902222222', 'Salom'),
    ]);

    expect($results)->toHaveCount(3)
        ->and($results[0]->successful)->toBeTrue()
        ->and($results[1]->successful)->toBeFalse()
        ->and($results[1]->phone)->toBe('+998971111111')
        ->and($results[2]->successful)->toBeTrue();

    Http::assertSentCount(1);

    Http::assertSent(fn (Request $request): bool => count($request['messages']) === 2
        && $request['messages'][0]['recipient'] === '998901111111'
        && $request['messages'][1]['recipient'] === '998902222222');

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('makes no request when every recipient is blocked', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['blocked' => ['998']]);

    Event::fake([SmsSent::class]);

    Http::fake();

    $results = app(DriverFactory::class)->make('eskiz')->sendMany(
        OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'),
    );

    expect($results)->toHaveCount(2)
        ->and($results->every(fn ($m): bool => ! $m->successful))->toBeTrue();

    Http::assertNothingSent();

    Event::assertDispatchedTimes(SmsSent::class, 2);
});

it('applies prefix rules in the loop fallback', function () {
    config()->set('sms.drivers.sayqal.prefixes', ['blocked' => ['99897']]);

    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 1])]);

    $results = app(DriverFactory::class)->make('sayqal')->sendMany(OutboundMessage::sameText(
        ['+998931111111', '+998971111111', '+998932222222'],
        'Salom',
    ));

    expect($results)->toHaveCount(3)
        ->and($results[1]->successful)->toBeFalse();

    Http::assertSentCount(2);
});

it('persists the blocked attempt as a failed row', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['blocked' => ['99897']]);

    Http::fake();

    app(DriverFactory::class)->make('eskiz')->send('+998971234567', 'Salom');

    $log = SmsLog::query()->sole();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toContain('blocked prefix');
});

it('normalizes prefixes and phones before matching', function () {
    config()->set('sms.drivers.eskiz.prefixes', ['blocked' => ['+998 97']]);

    Http::fake();

    $message = app(DriverFactory::class)->make('eskiz')->send('998-97-123-45-67', 'Salom');

    expect($message->successful)->toBeFalse();

    Http::assertNothingSent();
});
