<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Illuminate\Support\Collection;
use LogicException;
use Uzbek\Sms\Concerns\HasSendOptions;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Debug\DebugCollector;

final class PendingBulkMessage
{
    use HasSendOptions;

    /**
     * @param  iterable<\Uzbek\Sms\Data\OutboundMessage>  $messages
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly iterable $messages,
    ) {}

    /**
     * @return Collection<int, SentMessage>
     */
    public function send(): Collection
    {
        if ($this->sent) {
            throw new LogicException('Messages already sent. Build a new bulk message for each send.');
        }

        $this->sent = true;

        $fallback = $this->effectiveFallback();

        if (! $this->debug) {
            return $this->driver->sendMany($this->messages, $fallback, $this->fallbackWhen);
        }

        [$results, $entries] = app(DebugCollector::class)->capture(
            fn (): Collection => $this->driver->sendMany($this->messages, $fallback, $this->fallbackWhen),
        );

        foreach ($results as $message) {
            if (! $message->successful) {
                $entries[] = ['type' => 'exception', 'provider' => $message->provider, 'phone' => $message->phone, 'message' => $message->errorMessage];
            }
        }

        // Batch requests cover many recipients at once, so every result
        // carries the whole window trace rather than a per-message slice.
        return $results->each(function (SentMessage $message) use ($entries): void {
            $message->debug = $entries;
        });
    }
}
