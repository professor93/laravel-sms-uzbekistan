<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;

beforeEach(function () {
    config()->set('sms.fake.enabled', true);

    Http::fake(); // any HTTP call in fake mode is a bug — assertNothingSent() catches it
});

it('reports success without any HTTP call by default', function () {
    $result = sms('textup')->to('+998901234567')->text('Salom')->send();

    expect($result->successful)->toBeTrue()
        ->and($result->provider)->toBe('textup')
        ->and($result->status)->toBe(DeliveryStatus::Pending)
        ->and($result->providerMessageId)->toStartWith('fake-')
        ->and($result->raw)->toBe(['fake' => true]);

    Http::assertNothingSent();
});

it('always fails when the success rate is zero', function () {
    config()->set('sms.fake.success_rate', 0);

    $result = sms('textup')->to('+998901234567')->text('Salom')->withoutFallback()->send();

    expect($result->successful)->toBeFalse()
        ->and($result->errorMessage)->toBe('Simulated failure (fake mode).')
        ->and($result->raw)->toBe(['fake' => true]);

    Http::assertNothingSent();
});

it('fires SmsSent like a real send', function () {
    Event::fake([SmsSent::class]);

    sms('textup')->to('+998901234567')->text('Salom')->send();

    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('fakes bulk sends without HTTP', function () {
    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom');

    $results = sms('sayqal')->many($messages)->send();

    expect($results)->toHaveCount(2)
        ->and($results->get(0)->successful)->toBeTrue()
        ->and($results->get(1)->successful)->toBeTrue()
        ->and($results->get(0)->providerMessageId)->toStartWith('fake-');

    Http::assertNothingSent();
});

it('mixes outcomes across many messages at a partial rate', function () {
    config()->set('sms.fake.success_rate', 0.5);

    $messages = OutboundMessage::sameText(
        array_map(fn (int $i): string => sprintf('+9989011%05d', $i), range(1, 64)),
        'Salom',
    );

    $results = sms('sayqal')->many($messages)->withoutFallback()->send();

    $outcomes = $results->pluck('successful')->unique();

    expect($outcomes)->toHaveCount(2);

    Http::assertNothingSent();
});

it('lets a fake failure drive the normal fallback path', function () {
    config()->set('sms.fake.success_rate', 0);

    $result = sms('textup')->to('+998901234567')->text('Salom')->useFallback('playmobile')->send();

    expect($result->provider)->toBe('playmobile')
        ->and($result->fallbackFrom)->toBe('textup')
        ->and($result->successful)->toBeFalse();

    Http::assertNothingSent();
});

it('records fake entries for both providers in the debug trace', function () {
    config()->set('sms.fake.success_rate', 0);

    $result = sms('textup')->to('+998901234567')->text('Salom')->useFallback('playmobile')->debug()->send();

    $fakes = collect($result->debug)->where('type', 'fake')->values();

    expect($fakes)->toHaveCount(2)
        ->and($fakes->get(0)['provider'])->toBe('textup')
        ->and($fakes->get(1)['provider'])->toBe('playmobile')
        ->and(collect($result->debug)->firstWhere('type', 'fallback'))->toMatchArray(['from' => 'textup', 'to' => 'playmobile']);
});

it('does not interfere when disabled', function () {
    config()->set('sms.fake.enabled', false);

    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $result = sms('sayqal')->to('+998901234567')->text('Salom')->send();

    expect($result->successful)->toBeTrue()
        ->and($result->providerMessageId)->not->toStartWith('fake-');

    Http::assertSentCount(1);
});
