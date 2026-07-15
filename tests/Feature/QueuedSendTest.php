<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Jobs\SendBulkSmsJob;
use Uzbek\Sms\Jobs\SendSmsJob;

it('queues a single message with the fallback resolved at queue time', function () {
    config()->set('sms.providers.eskiz.fallback', 'playmobile');

    Queue::fake();

    Sms::to('+998901234567')->text('Salom')->queue();

    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => $job->provider === 'eskiz'
        && $job->phone === '+998901234567'
        && $job->text === 'Salom'
        && $job->fallback === 'playmobile');
});

it('schedules a delayed send on a named queue via later', function () {
    Queue::fake();

    Sms::to('+998901234567')->text('Salom')->later(300, 'sms');

    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => $job->queue === 'sms'
        && $job->delay === 300);
});

it('sends through the container-resolved factory when the job runs', function () {
    Sms::fake();

    (new SendSmsJob('eskiz', '+998901234567', 'Salom', ['from' => '4546'], null))
        ->handle(app(DriverFactory::class));

    Sms::assertSent(fn (SentMessage $m): bool => $m->provider === 'eskiz' && $m->text === 'Salom');
});

it('guards against double use across queue and send', function () {
    Queue::fake();

    $pending = Sms::to('+998901234567')->text('Salom');
    $pending->queue();

    expect(fn () => $pending->send())->toThrow(LogicException::class);
});

it('rejects queueing a closure-based fallback predicate', function () {
    Queue::fake();

    $pending = Sms::to('+998901234567')->text('Salom')->useFallback('playmobile', fn (SentMessage $m): bool => true);

    expect(fn () => $pending->queue())->toThrow(LogicException::class);
});

it('queues bulk messages as plain payloads', function () {
    Queue::fake();

    Sms::many(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'S'))->queue();

    Queue::assertPushed(SendBulkSmsJob::class, fn (SendBulkSmsJob $job): bool => count($job->messages) === 2
        && $job->messages[0]['phone'] === '+998901111111'
        && $job->messages[0]['text'] === 'S');
});

it('bulk job sends every message when it runs', function () {
    Sms::fake();

    (new SendBulkSmsJob('eskiz', [
        ['phone' => '+998901111111', 'text' => 'S'],
        ['phone' => '+998902222222', 'text' => 'S'],
    ], null))->handle(app(DriverFactory::class));

    Sms::assertSentCount(2);
});
