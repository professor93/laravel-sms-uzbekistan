<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelData\Data;
use Throwable;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Support\SegmentCalculator;

final class SentMessage extends Data
{
    public function segments(): SegmentInfo
    {
        return SegmentCalculator::for($this->text);
    }

    /**
     * segments × the provider's price_per_segment config; null when no price
     * is set. Computed on read so price changes need no reprocessing.
     */
    public function cost(): ?float
    {
        $price = config("sms.providers.{$this->provider}.price_per_segment");

        if (! is_numeric($price)) {
            return null;
        }

        return round($this->segments()->segments * (float) $price, 2);
    }

    /**
     * Single write path for status changes: the DeliveryStatusUpdated event
     * carries it to sms_logs (when database logging is on) and to any app
     * listener — same route a webhook takes.
     */
    public function updateStatus(DeliveryStatus $status): self
    {
        $this->status = $status;

        if ($this->providerMessageId !== null) {
            Event::dispatch(new DeliveryStatusUpdated(
                provider: $this->provider,
                providerMessageId: $this->providerMessageId,
                status: $status,
                raw: $this->raw,
            ));
        }

        return $this;
    }

    /**
     * Polls the provider and syncs. No-ops without a provider message id, on
     * drivers without ChecksDeliveryStatus, and on transport errors.
     */
    public function refreshStatus(): self
    {
        if ($this->providerMessageId === null) {
            return $this;
        }

        try {
            $driver = app(DriverFactory::class)->make($this->provider);

            if (! $driver instanceof ChecksDeliveryStatus) {
                return $this;
            }

            return $this->updateStatus($driver->checkStatus($this->providerMessageId));
        } catch (Throwable) {
            return $this;
        }
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @param  string|null  $fallbackFrom  primary provider whose failed attempt this message replaced
     * @param  list<array<string, mixed>>|null  $debug  HTTP/fallback trace, present only for debug()-enabled sends
     */
    public function __construct(
        public string $provider,
        public string $phone,
        public string $text,
        public DeliveryStatus $status,
        public bool $successful,
        public ?string $providerMessageId = null,
        public ?string $errorMessage = null,
        public array $raw = [],
        public ?string $fallbackFrom = null,
        public ?array $debug = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function success(
        string $provider,
        string $phone,
        string $text,
        ?string $providerMessageId,
        array $raw = [],
        DeliveryStatus $status = DeliveryStatus::Pending,
    ): self {
        return new self(
            provider: $provider,
            phone: $phone,
            text: $text,
            status: $status,
            successful: true,
            providerMessageId: $providerMessageId,
            raw: $raw,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function failed(
        string $provider,
        string $phone,
        string $text,
        ?string $errorMessage,
        array $raw = [],
    ): self {
        return new self(
            provider: $provider,
            phone: $phone,
            text: $text,
            status: DeliveryStatus::Failed,
            successful: false,
            errorMessage: $errorMessage,
            raw: $raw,
        );
    }
}
