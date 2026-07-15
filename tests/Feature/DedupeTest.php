<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Jobs\SendSmsJob;

it('skips the second send carrying the same dedupe key', function () {
    Sms::fake();

    $first = Sms::to('+998901234567')->text('Kod: 1234')->dedupe('otp:42')->send();
    $second = Sms::to('+998901234567')->text('Kod: 1234')->dedupe('otp:42')->send();

    expect($first->successful)->toBeTrue()
        ->and($second->successful)->toBeFalse()
        ->and($second->errorMessage)->toContain('dedupe');

    Sms::assertSentCount(1);
});

it('sends both messages when the keys differ', function () {
    Sms::fake();

    Sms::to('+998901234567')->text('A')->dedupe('otp:1')->send();
    Sms::to('+998901234567')->text('B')->dedupe('otp:2')->send();

    Sms::assertSentCount(2);
});

it('carries the dedupe key onto the queued job', function () {
    Queue::fake();

    Sms::to('+998901234567')->text('Salom')->dedupe('reg:7', 600)->queue();

    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => $job->dedupeKey === 'reg:7'
        && $job->dedupeTtl === 600);
});

it('a retried job with the same key sends only once', function () {
    Sms::fake();

    $job = new SendSmsJob('eskiz', '+998901234567', 'Kod: 9', [], null, 'otp:9');

    $job->handle(app(DriverFactory::class));
    $job->handle(app(DriverFactory::class));

    Sms::assertSentCount(1);
});

it('does not burn the dedupe key when the primary resolution fails', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $factory = app(DriverFactory::class);

    $broken = $factory->make('eskiz')->to('+998901234567')->text('S')->dedupe('cfg:1')->as(['enabled' => false]);

    expect(fn () => $broken->send())->toThrow(DriverDisabledException::class);

    // Config error must not consume the key — the corrected send goes through.
    $message = $factory->make('eskiz')->to('+998901234567')->text('S')->dedupe('cfg:1')->send();

    expect($message->successful)->toBeTrue();
});

it('dedupes a whole bulk batch', function () {
    Sms::fake();

    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'S');

    Sms::many($messages)->dedupe('campaign:5')->send();
    $second = Sms::many($messages)->dedupe('campaign:5')->send();

    Sms::assertSentCount(2);

    expect($second)->toHaveCount(2)
        ->and($second->every(fn ($m): bool => ! $m->successful))->toBeTrue();
});
