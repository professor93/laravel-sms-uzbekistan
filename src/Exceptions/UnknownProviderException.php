<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class UnknownProviderException extends SmsException
{
    public static function make(string $provider): self
    {
        return new self("SMS provider [{$provider}] is not defined. Add a config/sms.php providers block.");
    }
}
