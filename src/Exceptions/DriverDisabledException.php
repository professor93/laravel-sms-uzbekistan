<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class DriverDisabledException extends SmsException
{
    public static function make(string $provider): self
    {
        return new self(sprintf(
            'SMS provider [%s] is disabled. Enable it via sms.providers.%s.enabled.',
            $provider,
            $provider,
        ));
    }
}
