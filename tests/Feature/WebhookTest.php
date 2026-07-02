<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Models\SmsLog;

it('accepts a verified webhook and dispatches DeliveryStatusUpdated', function () {
    Event::fake([DeliveryStatusUpdated::class]);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [
            ['message-id' => 'MSG-1', 'status' => 'Delivered'],
            ['message-id' => 'MSG-2', 'status' => 'Rejected'],
        ],
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->driver === 'playmobile'
        && $event->providerMessageId === 'MSG-1'
        && $event->status === DeliveryStatus::Delivered);

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->providerMessageId === 'MSG-2'
        && $event->status === DeliveryStatus::Failed);

    Event::assertDispatchedTimes(DeliveryStatusUpdated::class, 2);
});

it('updates the matching sms_logs row by driver and provider id', function () {
    $log = SmsLog::query()->create([
        'driver' => 'playmobile',
        'provider_message_id' => 'MSG-9',
        'phone' => '998901234567',
        'text' => 'Salom',
        'status' => DeliveryStatus::Pending,
    ]);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-9', 'status' => 'Delivered']],
    ])->assertOk();

    expect($log->refresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('rejects a webhook with a bad token', function () {
    $this->postJson('/sms/webhooks/playmobile?token=wrong', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('accepts a webhook without a token when no secret is configured', function () {
    config()->set('sms.drivers.playmobile.webhook_secret', null);

    Event::fake([DeliveryStatusUpdated::class]);

    $this->postJson('/sms/webhooks/playmobile', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class);
});

it('still enforces the token once a secret is configured', function () {
    config()->set('sms.drivers.playmobile.webhook_secret', 'hook-secret');

    $this->postJson('/sms/webhooks/playmobile', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('rejects a webhook from an unlisted IP when the allowlist is set', function () {
    config()->set('sms.drivers.playmobile.allowed_ips', ['203.0.113.10']);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('returns 404 for a driver that does not handle webhooks', function () {
    $this->postJson('/sms/webhooks/textup?token=hook-secret', [])->assertNotFound();
});

it('returns 404 for an unknown driver', function () {
    $this->postJson('/sms/webhooks/nexmo?token=hook-secret', [])->assertNotFound();
});

it('returns 404 for a disabled driver', function () {
    config()->set('sms.drivers.playmobile.enabled', false);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertNotFound();
});
