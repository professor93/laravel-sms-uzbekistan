<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Facades\Sms;

it('records sends without HTTP or events', function () {
    Event::fake([SmsSent::class]);
    Http::fake();

    $fake = Sms::fake();

    $message = Sms::to('+998901234567')->text('Salom')->send();

    expect($message->successful)->toBeTrue()
        ->and($message->provider)->toBe('eskiz');

    $fake->assertSent(fn (SentMessage $m): bool => $m->phone === '+998901234567' && $m->text === 'Salom');
    Sms::assertSentCount(1);

    Http::assertNothingSent();
    Event::assertNotDispatched(SmsSent::class);
});

it('records sends from named providers and the helper', function () {
    Sms::fake();

    sms('playmobile')->send('+998901111111', 'A');
    app(DriverFactory::class)->make('sayqal')->to('+998902222222')->text('B')->send();

    Sms::assertSentCount(2);
    Sms::assertSent(fn (SentMessage $m): bool => $m->provider === 'playmobile');
    Sms::assertSent(fn (SentMessage $m): bool => $m->provider === 'sayqal' && $m->text === 'B');
});

it('asserts nothing sent', function () {
    Sms::fake();

    Sms::assertNothingSent();
});

it('matches recipients by digits regardless of formatting', function () {
    Sms::fake();

    Sms::to('+998 90 123-45-67')->text('Salom')->send();

    Sms::assertSentTo('998901234567');
});

it('records bulk sends per message', function () {
    Sms::fake();

    Sms::many(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'S'))->send();

    Sms::assertSentCount(2);
});

it('fails the assertion when nothing matches', function () {
    Sms::fake();

    expect(fn () => Sms::assertSent())->toThrow(AssertionFailedError::class);
});

it('exposes the recorded messages for custom expectations', function () {
    Sms::fake();

    Sms::to('+998901234567')->text('Salom')->send();

    expect(Sms::sent())->toHaveCount(1)
        ->and(Sms::sent()->first()->text)->toBe('Salom');
});
