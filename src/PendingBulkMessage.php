<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use LogicException;
use Uzbek\Sms\Concerns\HasSendOptions;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Debug\DebugCollector;
use Uzbek\Sms\Jobs\SendBulkSmsJob;

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

    public function queue(?string $queue = null): PendingDispatch
    {
        if ($this->sent) {
            throw new LogicException('Messages already sent. Build a new bulk message for each send.');
        }

        $this->guardTemplateCompliance($this->texts());

        if ($this->debug) {
            throw new LogicException('debug() traces the live HTTP exchange and cannot be queued.');
        }

        if ($this->fallbackWhen !== null) {
            throw new LogicException('useFallback() with a Closure predicate cannot be queued.');
        }

        // Fallback resolves now so the job survives later config changes.
        $job = new SendBulkSmsJob(
            provider: $this->driver->name(),
            messages: Collection::make($this->messages)
                ->values()
                ->map(fn (OutboundMessage $message): array => ['phone' => $message->phone, 'text' => $message->text])
                ->all(),
            fallback: $this->effectiveFallback(),
            dedupeKey: $this->dedupeKey,
            dedupeTtl: $this->dedupeTtl,
        );

        $this->sent = true;

        $dispatch = dispatch($job);

        if ($queue !== null) {
            $dispatch->onQueue($queue);
        }

        return $dispatch;
    }

    public function later(DateTimeInterface|DateInterval|int $delay, ?string $queue = null): PendingDispatch
    {
        return $this->queue($queue)->delay($delay);
    }

    /**
     * @return list<string>
     */
    private function texts(): array
    {
        return Collection::make($this->messages)
            ->map(fn (OutboundMessage $message): string => $message->text)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, SentMessage>
     */
    public function send(): Collection
    {
        if ($this->sent) {
            throw new LogicException('Messages already sent. Build a new bulk message for each send.');
        }

        $this->guardTemplateCompliance($this->texts());

        $this->sent = true;

        if (! $this->reserveDedupe()) {
            return Collection::make($this->messages)
                ->values()
                ->map(fn (OutboundMessage $message): SentMessage => $this->duplicateResult($message->phone, $message->text));
        }

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
