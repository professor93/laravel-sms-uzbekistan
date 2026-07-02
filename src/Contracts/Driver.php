<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Illuminate\Support\Collection;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\PendingMessage;

interface Driver
{
    public function send(string $phone, string $text): SentMessage;

    /**
     * @param  iterable<OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    public function sendMany(iterable $messages): Collection;

    public function to(string $phone): PendingMessage;

    public function name(): string;
}
