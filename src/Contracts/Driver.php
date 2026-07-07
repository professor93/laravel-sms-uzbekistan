<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Closure;
use Illuminate\Support\Collection;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\PendingBulkMessage;
use Uzbek\Sms\PendingMessage;

interface Driver
{
    public function send(string $phone, string $text): SentMessage;

    /**
     * @param  iterable<OutboundMessage>  $messages
     * @param  Closure(SentMessage): bool|null  $fallbackWhen
     * @return Collection<int, SentMessage>
     */
    public function sendMany(iterable $messages, ?string $fallback = null, ?Closure $fallbackWhen = null): Collection;

    public function to(string $phone): PendingMessage;

    public function many(iterable $messages): PendingBulkMessage;

    public function name(): string;

    public function defaultFallback(): ?string;
}
