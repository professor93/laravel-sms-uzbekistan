<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Models\SmsLog;
use Uzbek\Sms\Tests\Support\RecordingWebhookHandler;

beforeEach(function (): void {
    RecordingWebhookHandler::$calls = [];
});

it('accepts a verified webhook and dispatches DeliveryStatusUpdated', function () {
    Event::fake([DeliveryStatusUpdated::class]);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [
            ['message-id' => 'MSG-1', 'status' => 'Delivered'],
            ['message-id' => 'MSG-2', 'status' => 'Rejected'],
        ],
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->provider === 'playmobile'
        && $event->providerMessageId === 'MSG-1'
        && $event->status === DeliveryStatus::Delivered);

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->providerMessageId === 'MSG-2'
        && $event->status === DeliveryStatus::Failed);

    Event::assertDispatchedTimes(DeliveryStatusUpdated::class, 2);
});

it('updates the matching sms_logs row by driver and provider id', function () {
    $log = SmsLog::query()->create([
        'provider' => 'playmobile',
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
    config()->set('sms.providers.playmobile.webhook_secret', null);

    Event::fake([DeliveryStatusUpdated::class]);

    $this->postJson('/sms/webhooks/playmobile', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class);
});

it('still enforces the token once a secret is configured', function () {
    config()->set('sms.providers.playmobile.webhook_secret', 'hook-secret');

    $this->postJson('/sms/webhooks/playmobile', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('rejects a webhook from an unlisted IP when the allowlist is set', function () {
    config()->set('sms.providers.playmobile.allowed_ips', ['203.0.113.10']);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('accepts an exact allowed IP', function () {
    config()->set('sms.providers.playmobile.allowed_ips', ['127.0.0.1']);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();
});

it('accepts an IP inside an allowed CIDR range', function () {
    config()->set('sms.providers.playmobile.allowed_ips', ['127.0.0.0/8']);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();
});

it('rejects an IP outside the allowed CIDR range', function () {
    config()->set('sms.providers.playmobile.allowed_ips', ['10.0.0.0/8']);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertForbidden();
});

it('matches IPv6 addresses against IPv6 CIDR ranges', function () {
    config()->set('sms.providers.playmobile.allowed_ips', ['2001:db8::/32']);

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::10'])
        ->postJson('/sms/webhooks/playmobile?token=hook-secret', [
            'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
        ])->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db9::10'])
        ->postJson('/sms/webhooks/playmobile?token=hook-secret', [
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
    config()->set('sms.providers.playmobile.enabled', false);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertNotFound();
});

it('invokes a configured webhook handler with the parsed reports', function () {
    config()->set('sms.providers.playmobile.webhook_handler', RecordingWebhookHandler::class);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();

    expect(RecordingWebhookHandler::$calls)->toHaveCount(1);

    $call = RecordingWebhookHandler::$calls[0];

    expect($call['provider'])->toBe('playmobile')
        ->and($call['reports'])->toHaveCount(1)
        ->and($call['reports'][0]->providerMessageId)->toBe('MSG-1')
        ->and($call['reports'][0]->status)->toBe(DeliveryStatus::Delivered);
});

it('still dispatches DeliveryStatusUpdated when a handler is configured', function () {
    Event::fake([DeliveryStatusUpdated::class]);

    config()->set('sms.providers.playmobile.webhook_handler', RecordingWebhookHandler::class);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class);
});

it('serves webhooks for a driver without HandlesWebhooks when a handler is configured', function () {
    config()->set('sms.providers.textup.webhook_handler', RecordingWebhookHandler::class);

    $this->postJson('/sms/webhooks/textup', ['event' => 'delivered', 'id' => 'X1'])->assertOk();

    expect(RecordingWebhookHandler::$calls)->toHaveCount(1)
        ->and(RecordingWebhookHandler::$calls[0]['reports'])->toBe([])
        ->and(RecordingWebhookHandler::$calls[0]['payload'])->toBe(['event' => 'delivered', 'id' => 'X1']);
});

it('returns 500 when the configured handler does not implement WebhookHandler', function () {
    config()->set('sms.providers.playmobile.webhook_handler', stdClass::class);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertStatus(500);
});

it('logs the webhook by default when no handler is configured', function () {
    $entries = [];
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use (&$entries): void {
        $entries[] = compact('message', 'context');
    });

    Log::partialMock()->shouldReceive('channel')->with(null)->andReturn($channel);

    $this->postJson('/sms/webhooks/playmobile?token=hook-secret', [
        'messages' => [['message-id' => 'MSG-1', 'status' => 'Delivered']],
    ])->assertOk();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context']['provider'])->toBe('playmobile');
});

it('accepts an eskiz delivery callback and dispatches DeliveryStatusUpdated', function () {
    Event::fake([DeliveryStatusUpdated::class]);

    $this->postJson('/sms/webhooks/eskiz', [
        'message_id' => '4385062',
        'user_sms_id' => 'ulid-1',
        'status' => 'DELIVRD',
    ])->assertOk();

    Event::assertDispatched(DeliveryStatusUpdated::class, fn (DeliveryStatusUpdated $event): bool => $event->provider === 'eskiz'
        && $event->providerMessageId === '4385062'
        && $event->status === DeliveryStatus::Delivered);
});

it('enforces the eskiz webhook token once a secret is configured', function () {
    config()->set('sms.providers.eskiz.webhook_secret', 'esk-secret');

    $this->postJson('/sms/webhooks/eskiz', ['message_id' => '1', 'status' => 'DELIVRD'])
        ->assertForbidden();

    $this->postJson('/sms/webhooks/eskiz?token=esk-secret', ['message_id' => '1', 'status' => 'DELIVRD'])
        ->assertOk();
});
