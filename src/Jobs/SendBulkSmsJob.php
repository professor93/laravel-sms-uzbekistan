<?php

declare(strict_types=1);

namespace Uzbek\Sms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;

final class SendBulkSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<array{phone: string, text: string}>  $messages
     * @param  string|null  $fallback  resolved at queue time; null = no fallback
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $messages,
        public readonly ?string $fallback = null,
    ) {}

    public function handle(DriverFactory $factory): void
    {
        $messages = array_map(
            fn (array $message): OutboundMessage => new OutboundMessage($message['phone'], $message['text']),
            $this->messages,
        );

        $pending = $factory->make($this->provider)->many($messages);

        $this->fallback === null
            ? $pending->withoutFallback()
            : $pending->useFallback($this->fallback);

        $pending->send();
    }
}
