<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class UnknownDriverException extends SmsException
{
    public static function make(string $driver): self
    {
        return new self(
            "SMS driver [{$driver}] is not defined. Use a built-in name or an AbstractDriver subclass."
        );
    }
}
