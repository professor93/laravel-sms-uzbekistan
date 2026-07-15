<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Models\SmsLog;

function sendEskiz(): SentMessage
{
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 555, 'status' => 'waiting']),
        'notify.eskiz.uz/api/message/sms/status_by_id/*' => Http::response(['data' => ['status' => 'DELIVERED']]),
    ]);

    return app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');
}

it('updateStatus mutates the message and syncs the database row', function () {
    $message = sendEskiz();

    expect(SmsLog::query()->sole()->status)->toBe(DeliveryStatus::Pending);

    $message->updateStatus(DeliveryStatus::Delivered);

    expect($message->status)->toBe(DeliveryStatus::Delivered)
        ->and(SmsLog::query()->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('updateStatus dispatches DeliveryStatusUpdated', function () {
    $message = sendEskiz();

    Event::fake([DeliveryStatusUpdated::class]);

    $message->updateStatus(DeliveryStatus::Undelivered);

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->provider === 'eskiz'
        && $event->providerMessageId === '555'
        && $event->status === DeliveryStatus::Undelivered);
});

it('refreshStatus polls the provider and syncs everything', function () {
    $message = sendEskiz();

    $message->refreshStatus();

    expect($message->status)->toBe(DeliveryStatus::Delivered)
        ->and(SmsLog::query()->sole()->status)->toBe(DeliveryStatus::Delivered);
});

it('refreshStatus is a no-op without a provider message id', function () {
    $message = SentMessage::failed('eskiz', '998901234567', 'Salom', 'boom');

    expect($message->refreshStatus()->status)->toBe(DeliveryStatus::Failed);
});

it('refreshStatus is a no-op for drivers without status support', function () {
    $message = SentMessage::success('playmobile', '998901234567', 'Salom', 'MSG-1');

    expect($message->refreshStatus()->status)->toBe(DeliveryStatus::Pending);
});

it('refreshStatus swallows transport errors and keeps the old status', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/status_by_id/*' => Http::response(['message' => 'down'], 500),
    ]);

    $message = SentMessage::success('eskiz', '998901234567', 'Salom', '777');

    expect($message->refreshStatus()->status)->toBe(DeliveryStatus::Pending);
});
