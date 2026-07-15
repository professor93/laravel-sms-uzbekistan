<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;

function batchCalls(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'send-batch'))
        ->map(fn (Request $request): int => count($request['messages']))
        ->values()
        ->all();
}

function fakeEskizBatch(): void
{
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send-batch' => Http::response(['id' => 7]),
    ]);
}

it('splits a bulk send into configured chunks', function () {
    config()->set('sms.providers.eskiz.bulk', ['chunk' => 2]);

    fakeEskizBatch();

    $results = app(DriverFactory::class)->make('eskiz')->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333', '+998904444444', '+998905555555'],
        'S',
    ));

    expect($results)->toHaveCount(5)
        ->and($results->every(fn ($m): bool => $m->successful))->toBeTrue()
        ->and(batchCalls())->toBe([2, 2, 1]);
});

it('keeps result order across chunks', function () {
    config()->set('sms.providers.eskiz.bulk', ['chunk' => 2]);

    fakeEskizBatch();

    $results = app(DriverFactory::class)->make('eskiz')->sendMany([
        new OutboundMessage('+998901111111', 'T1'),
        new OutboundMessage('+998902222222', 'T2'),
        new OutboundMessage('+998903333333', 'T3'),
    ]);

    expect($results->pluck('text')->all())->toBe(['T1', 'T2', 'T3'])
        ->and($results->pluck('phone')->all())->toBe(['998901111111', '998902222222', '998903333333']);
});

it('paces chunks against the per-second cap', function () {
    config()->set('sms.providers.eskiz.bulk', ['per_second' => 2]);

    Sleep::fake();

    fakeEskizBatch();

    app(DriverFactory::class)->make('eskiz')->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333', '+998904444444', '+998905555555'],
        'S',
    ));

    expect(batchCalls())->toBe([2, 2, 1]);

    Sleep::assertSleptTimes(2);
});

it('does not sleep when only a chunk size is set', function () {
    config()->set('sms.providers.eskiz.bulk', ['chunk' => 2]);

    Sleep::fake();

    fakeEskizBatch();

    app(DriverFactory::class)->make('eskiz')->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333'],
        'S',
    ));

    Sleep::assertNeverSlept();
});

it('sends one batch when no bulk config is set', function () {
    fakeEskizBatch();

    app(DriverFactory::class)->make('eskiz')->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333'],
        'S',
    ));

    expect(batchCalls())->toBe([3]);
});
