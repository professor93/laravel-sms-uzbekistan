<?php

declare(strict_types=1);

namespace Uzbek\Sms\Testing;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Uzbek\Sms\Contracts\ChecksBalance;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\Balance;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\PendingBulkMessage;
use Uzbek\Sms\PendingMessage;

/**
 * Stand-in driver used by Sms::fake(): records every send, touches nothing —
 * no HTTP, no events, no listeners.
 */
final class RecordingDriver implements ChecksBalance, Driver
{
    public function __construct(
        private readonly string $provider,
        private readonly SmsFake $fake,
    ) {}

    public function balance(): Balance
    {
        return new Balance(amount: 999999.0, currency: null, raw: ['fake' => true]);
    }

    public function send(string $phone, string $text): SentMessage
    {
        $message = SentMessage::success(
            provider: $this->provider,
            phone: $phone,
            text: $text,
            providerMessageId: 'fake-'.Str::ulid(),
            raw: ['fake' => true],
        );

        $this->fake->record($message);

        return $message;
    }

    public function sendMany(iterable $messages, ?string $fallback = null, ?Closure $fallbackWhen = null): Collection
    {
        return Collection::make($messages)
            ->values()
            ->map(fn (OutboundMessage $message): SentMessage => $this->send($message->phone, $message->text));
    }

    public function to(string $phone): PendingMessage
    {
        return (new PendingMessage($this))->to($phone);
    }

    public function many(iterable $messages): PendingBulkMessage
    {
        return new PendingBulkMessage($this, $messages);
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function defaultFallback(): ?string
    {
        $fallback = config("sms.providers.{$this->provider}.fallback");

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }
}
