<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;

beforeEach(function () {
    // eskiz fails -> should fall back; sayqal (the configured default) succeeds.
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['message' => 'boom'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 9]),
    ]);
});

it('auto-applies the provider-configured default fallback on a single send', function () {
    config()->set('sms.providers.eskiz.fallback', 'sayqal');

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->send();

    expect($result->successful)->toBeTrue()
        ->and($result->provider)->toBe('sayqal')
        ->and($result->fallbackFrom)->toBe('eskiz');
});

it('lets an explicit useFallback override the configured default', function () {
    config()->set('sms.providers.eskiz.fallback', 'sayqal');

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->useFallback('playmobile')->send();

    expect($result->provider)->toBe('playmobile');
});

it('withoutFallback() suppresses the configured default on a single send', function () {
    config()->set('sms.providers.eskiz.fallback', 'sayqal');

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->withoutFallback()->send();

    expect($result->successful)->toBeFalse()
        ->and($result->provider)->toBe('eskiz');
});

it('auto-applies the configured default fallback through many()', function () {
    config()->set('sms.providers.sayqal.fallback', 'eskiz');
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);

    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 9]),
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 5, 'status' => 'waiting']),
    ]);

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    $results = app(DriverFactory::class)->make('sayqal')->many($messages)->send();

    expect($results->get(0)->provider)->toBe('sayqal')
        ->and($results->get(1)->provider)->toBe('eskiz'); // blocked -> configured default fallback
});

it('raw sendMany does not auto-apply the configured default fallback', function () {
    config()->set('sms.providers.sayqal.fallback', 'eskiz');
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);

    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 9])]);

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    $results = app(DriverFactory::class)->make('sayqal')->sendMany($messages);

    // message 1 is a failed sayqal result, NOT re-sent (raw primitive, no default)
    expect($results->get(1)->provider)->toBe('sayqal')
        ->and($results->get(1)->successful)->toBeFalse();
});
