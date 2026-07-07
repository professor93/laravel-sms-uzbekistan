<?php

declare(strict_types=1);

namespace Uzbek\Sms\Listeners;

use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Models\SmsLog;

final class UpdateDeliveryStatus
{
    public function handle(DeliveryStatusUpdated $event): void
    {
        SmsLog::query()
            ->where('provider', $event->provider)
            ->where('provider_message_id', $event->providerMessageId)
            // Query-builder updates bypass Eloquent casts — bind the value.
            ->update(['status' => $event->status->value]);
    }
}
