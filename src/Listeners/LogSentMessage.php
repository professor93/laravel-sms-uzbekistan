<?php

declare(strict_types=1);

namespace Uzbek\Sms\Listeners;

use Illuminate\Support\Facades\Log;
use Uzbek\Sms\Events\SmsSent;

final class LogSentMessage
{
    public function handle(SmsSent $event): void
    {
        $message = $event->message;

        Log::channel(config('sms.logging.channel'))->log(
            $message->successful ? 'info' : 'warning',
            'SMS send attempt',
            [
                'provider' => $message->provider,
                'phone' => $message->phone,
                'provider_message_id' => $message->providerMessageId,
                'status' => $message->status->value,
                'successful' => $message->successful,
                'error' => $message->errorMessage,
            ],
        );
    }
}
