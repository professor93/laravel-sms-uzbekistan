<?php

declare(strict_types=1);

namespace Uzbek\Sms\Listeners;

use Illuminate\Support\Facades\Log;
use Uzbek\Sms\Events\DeliveryStatusUpdated;

final class LogDeliveryStatusUpdate
{
    public function handle(DeliveryStatusUpdated $event): void
    {
        Log::channel(config('sms.logging.channel'))->info('SMS delivery status updated', [
            'provider' => $event->provider,
            'provider_message_id' => $event->providerMessageId,
            'status' => $event->status->value,
        ]);
    }
}
