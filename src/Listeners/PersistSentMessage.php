<?php

declare(strict_types=1);

namespace Uzbek\Sms\Listeners;

use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Models\SmsLog;

final class PersistSentMessage
{
    public function handle(SmsSent $event): void
    {
        $message = $event->message;

        $attributes = [
            'phone' => $message->phone,
            'text' => $message->text,
            'status' => $message->status,
            'error' => $message->errorMessage,
            'payload' => $message->raw,
        ];

        if ($message->providerMessageId === null) {
            SmsLog::query()->create([
                'driver' => $message->driver,
                'provider_message_id' => null,
                ...$attributes,
            ]);

            return;
        }

        // Upsert keeps the unique (driver, provider_message_id) index safe.
        SmsLog::query()->updateOrCreate(
            ['driver' => $message->driver, 'provider_message_id' => $message->providerMessageId],
            $attributes,
        );
    }
}
