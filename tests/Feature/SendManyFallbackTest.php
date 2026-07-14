<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\SmsSent;

function fakeSayqalAndEskiz(): void
{
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 100]),
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 55, 'status' => 'waiting']),
        // Eskiz as a fallback re-sends via sendMany -> its native batch endpoint:
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['status' => 'waiting']),
    ]);
}

it('re-sends only the failed bulk messages through the fallback provider', function () {
    // The second number is blocked by sayqal's prefix guard -> deterministic per-message failure.
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);
    fakeSayqalAndEskiz();

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    $results = app(DriverFactory::class)->make('sayqal')->sendMany($messages, fallback: 'eskiz');

    expect($results)->toHaveCount(2)
        ->and($results->get(0)->provider)->toBe('sayqal')
        ->and($results->get(0)->successful)->toBeTrue()
        ->and($results->get(0)->fallbackFrom)->toBeNull()
        ->and($results->get(1)->provider)->toBe('eskiz')
        ->and($results->get(1)->successful)->toBeTrue()
        ->and($results->get(1)->fallbackFrom)->toBe('sayqal');
});

it('honors a custom when predicate, re-sending even successful messages', function () {
    fakeSayqalAndEskiz();

    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom');

    $results = app(DriverFactory::class)->make('sayqal')
        ->sendMany($messages, fallback: 'eskiz', fallbackWhen: fn (SentMessage $m): bool => true);

    expect($results->get(0)->provider)->toBe('eskiz')
        ->and($results->get(1)->provider)->toBe('eskiz');
});

it('does not contact the fallback when every primary message succeeds', function () {
    fakeSayqalAndEskiz();

    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom');

    app(DriverFactory::class)->make('sayqal')->sendMany($messages, fallback: 'eskiz');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'notify.eskiz.uz'));
});

it('fires SmsSent for each real attempt including the fallback retry', function () {
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);
    fakeSayqalAndEskiz();
    Event::fake([SmsSent::class]);

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    app(DriverFactory::class)->make('sayqal')->sendMany($messages, fallback: 'eskiz');

    // msg0 sayqal success + msg1 sayqal rejection + msg1 eskiz fallback = 3
    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('logs a warning and returns primary results when an unsupported driver has a failed message', function () {
    config()->set('sms.providers.eskiz.prefixes', ['blocked' => ['99899']]);

    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('warning')->once()
        ->with(Mockery::pattern('/does not support bulk fallback/'));
    Log::partialMock()->shouldReceive('channel')->andReturn($channel);

    $results = app(DriverFactory::class)->make('eskiz')
        ->sendMany(OutboundMessage::sameText(['+998998990000'], 'Salom'), fallback: 'sayqal');

    expect($results)->toHaveCount(1)
        ->and($results->get(0)->provider)->toBe('eskiz')
        ->and($results->get(0)->successful)->toBeFalse();
});

it('does not warn on a fully successful bulk send even for an unsupported driver', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['status' => 'waiting']),
    ]);

    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('warning')->never();
    Log::partialMock()->shouldReceive('channel')->andReturn($channel);

    app(DriverFactory::class)->make('eskiz')
        ->sendMany(OutboundMessage::sameText(['+998901111111'], 'Salom'), fallback: 'sayqal');
});

it('stays quiet for an unsupported driver when sms.silent is on', function () {
    config()->set('sms.silent', true);
    config()->set('sms.providers.eskiz.prefixes', ['blocked' => ['99899']]);

    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('warning')->never();
    Log::partialMock()->shouldReceive('channel')->andReturn($channel);

    app(DriverFactory::class)->make('eskiz')
        ->sendMany(OutboundMessage::sameText(['+998998990000'], 'Salom'), fallback: 'sayqal');
});

it('behaves exactly as before when no fallback is given', function () {
    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7])]);

    $results = app(DriverFactory::class)->make('sayqal')
        ->sendMany(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'));

    expect($results)->toHaveCount(2)
        ->and($results->every(fn (SentMessage $m): bool => $m->provider === 'sayqal'))->toBeTrue();
});

it('drives the same fallback through the fluent many() builder as the params form', function () {
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);
    fakeSayqalAndEskiz();

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    $results = app(DriverFactory::class)->make('sayqal')
        ->many($messages)
        ->useFallback('eskiz')
        ->send();

    expect($results)->toHaveCount(2)
        ->and($results->get(0)->provider)->toBe('sayqal')
        ->and($results->get(1)->provider)->toBe('eskiz');
});
