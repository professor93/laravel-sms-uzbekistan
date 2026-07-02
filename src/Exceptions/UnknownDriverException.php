<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class UnknownDriverException extends SmsException
{
    public static function make(string $driver): self
    {
        return new self(
            "SMS driver [{$driver}] is not defined. Register it in the DriverFactory map and add a config/sms.php block."
        );
    }
}
