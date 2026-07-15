<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Exceptions\SmsException;
use Uzbek\Sms\Exceptions\TemplateViolationException;
use Uzbek\Sms\Facades\Sms;

beforeEach(function (): void {
    config()->set('sms.templates.list', [
        'welcome' => 'Xush kelibsiz, :name!',
        'otp' => 'Tasdiqlash kodi: :code',
    ]);
});

it('renders a named template through the builder', function () {
    Sms::fake();

    Sms::to('+998901234567')->template('welcome', ['name' => 'Ali'])->send();

    Sms::assertSent(fn (SentMessage $m): bool => $m->text === 'Xush kelibsiz, Ali!');
});

it('throws for an unknown template name', function () {
    Sms::fake();

    Sms::to('+998901234567')->template('missing');
})->throws(SmsException::class, 'not defined');

it('sends arbitrary text while enforcement is off', function () {
    Sms::fake();

    expect(Sms::to('+998901234567')->text('Erkin matn')->send()->successful)->toBeTrue();
});

it('allows template-shaped text under enforcement', function () {
    config()->set('sms.templates.enforce', true);

    Sms::fake();

    expect(Sms::to('+998901234567')->text('Tasdiqlash kodi: 4821')->send()->successful)->toBeTrue();
});

it('throws for non-matching text under enforcement', function () {
    config()->set('sms.templates.enforce', true);

    Sms::fake();

    expect(fn () => Sms::to('+998901234567')->text('Reklama!')->send())
        ->toThrow(TemplateViolationException::class);

    Sms::assertNothingSent();
});

it('enforces every message of a bulk send', function () {
    config()->set('sms.templates.enforce', true);

    Sms::fake();

    $messages = [
        new OutboundMessage('+998901111111', 'Tasdiqlash kodi: 1111'),
        new OutboundMessage('+998902222222', 'Erkin matn'),
    ];

    expect(fn () => Sms::many($messages)->send())->toThrow(TemplateViolationException::class);

    Sms::assertNothingSent();
});

it('enforces on the queue path too', function () {
    config()->set('sms.templates.enforce', true);

    Queue::fake();

    expect(fn () => Sms::to('+998901234567')->text('Reklama!')->queue())
        ->toThrow(TemplateViolationException::class);
});

it('rendered templates always pass enforcement', function () {
    config()->set('sms.templates.enforce', true);

    Sms::fake();

    $message = Sms::to('+998901234567')->template('welcome', ['name' => 'Zulfiya'])->send();

    expect($message->successful)->toBeTrue();
});
