<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Models\SmsLog;

it('persists a successful send to sms_logs', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 555, 'status' => 'waiting']),
    ]);

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    $log = SmsLog::query()->sole();

    expect($log->driver)->toBe('eskiz')
        ->and($log->provider_message_id)->toBe('555')
        ->and($log->phone)->toBe('998901234567')
        ->and($log->text)->toBe('Salom')
        ->and($log->status)->toBe(DeliveryStatus::Pending)
        ->and($log->error)->toBeNull()
        ->and($log->payload)->toBeArray();
});

it('persists failed sends with the error message', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    $log = SmsLog::query()->sole();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->not->toBeNull();
});

it('keeps the bulk loop alive when a listener throws after transmission', function () {
    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 1])]);

    Event::listen(\Uzbek\Sms\Events\SmsSent::class, function (): void {
        throw new RuntimeException('listener boom');
    });

    $results = app(DriverFactory::class)->make('sayqal')->sendMany(
        \Uzbek\Sms\Data\OutboundMessage::sameText(['+998931111111', '+998932222222'], 'Salom'),
    );

    expect($results)->toHaveCount(2)
        ->and($results->every(fn ($m): bool => $m->successful))->toBeTrue();

    Http::assertSentCount(2); // second message still went out
});

it('updates a row status when DeliveryStatusUpdated is dispatched', function () {
    SmsLog::query()->create([
        'driver' => 'eskiz',
        'provider_message_id' => '555',
        'phone' => '998901234567',
        'text' => 'Salom',
        'status' => DeliveryStatus::Pending,
    ]);

    Event::dispatch(new DeliveryStatusUpdated('eskiz', '555', DeliveryStatus::Delivered, []));

    expect(SmsLog::query()->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('leaves rows of other drivers untouched', function () {
    SmsLog::query()->create([
        'driver' => 'playmobile',
        'provider_message_id' => '555',
        'phone' => '998901234567',
        'text' => 'Salom',
        'status' => DeliveryStatus::Pending,
    ]);

    Event::dispatch(new DeliveryStatusUpdated('eskiz', '555', DeliveryStatus::Delivered, []));

    expect(SmsLog::query()->sole()->status)->toBe(DeliveryStatus::Pending);
});
