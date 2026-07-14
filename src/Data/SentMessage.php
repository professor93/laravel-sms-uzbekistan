<?php

declare(strict_types=1);

namespace Uzbek\Sms\Data;

use Spatie\LaravelData\Data;
use Uzbek\Sms\Enums\DeliveryStatus;

final class SentMessage extends Data
{
    /**
     * @param  array<array-key, mixed>  $raw
     * @param  string|null  $fallbackFrom  primary provider whose failed attempt this message replaced
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
