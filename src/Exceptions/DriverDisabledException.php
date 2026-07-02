<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class DriverDisabledException extends SmsException
{
    public static function make(string $driver): self
    {
        return new self(sprintf(
            'SMS driver [%s] is disabled. Enable it via %s_ENABLED or config/sms.php.',
            $driver,
            strtoupper($driver),
        ));
    }
}
