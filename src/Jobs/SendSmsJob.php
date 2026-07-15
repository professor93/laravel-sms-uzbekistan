<?php

declare(strict_types=1);

namespace Uzbek\Sms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Uzbek\Sms\DriverFactory;

/**
 * Provider failures become SentMessage::failed + events, exactly like a sync
 * send — the job itself never throws, so queue retries stay for infrastructure
 * errors only.
 */
final class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $overrides  builder options and credential overrides
     * @param  string|null  $fallback  resolved at queue time; null = no fallback
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $phone,
        public readonly string $text,
        public readonly array $overrides = [],
        public readonly ?string $fallback = null,
        public readonly ?string $dedupeKey = null,
        public readonly int $dedupeTtl = 86400,
    ) {}

    public function handle(DriverFactory $factory): void
    {
        $pending = $factory->make($this->provider)->to($this->phone)->text($this->text);

        if ($this->overrides !== []) {
            $pending->as($this->overrides);
        }

        if ($this->dedupeKey !== null) {
            $pending->dedupe($this->dedupeKey, $this->dedupeTtl);
        }

        $this->fallback === null
            ? $pending->withoutFallback()
            : $pending->useFallback($this->fallback);

        $pending->send();
    }
}
