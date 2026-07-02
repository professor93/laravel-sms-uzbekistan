<?php

declare(strict_types=1);

namespace Uzbek\Sms\Events;

use Uzbek\Sms\Data\SentMessage;

final class SmsSent
{
    public function __construct(
        public readonly SentMessage $message,
    ) {}
}
