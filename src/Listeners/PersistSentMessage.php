<?php

declare(strict_types=1);

namespace Uzbek\Sms\Listeners;

use Illuminate\Support\Facades\Schema;
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

        // Installs that have not run the cost migration keep logging without it.
        if (once(fn (): bool => Schema::hasColumn((new SmsLog)->getTable(), 'cost'))) {
            $attributes['segments'] = $message->segments()->segments;
            $attributes['cost'] = $message->cost();
        }

        if ($message->providerMessageId === null) {
            SmsLog::query()->create([
                'provider' => $message->provider,
                'provider_message_id' => null,
                ...$attributes,
            ]);

            return;
        }

        // Upsert keeps the unique (provider, provider_message_id) index safe.
        SmsLog::query()->updateOrCreate(
            ['provider' => $message->provider, 'provider_message_id' => $message->providerMessageId],
            $attributes,
        );
    }
}
