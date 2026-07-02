<?php

declare(strict_types=1);

namespace Uzbek\Sms\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::Undelivered, self::Failed => true,
            self::Pending, self::Sent, self::Unknown => false,
        };
    }
}
