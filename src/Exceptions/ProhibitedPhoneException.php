<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class ProhibitedPhoneException extends SmsException
{
    public static function blocked(string $phone, string $prefix): self
    {
        return new self("Phone [{$phone}] matches blocked prefix [{$prefix}]. See sms.prefixes in config/sms.php.");
    }

    public static function notAllowed(string $phone): self
    {
        return new self("Phone [{$phone}] does not match any allowed prefix. See sms.prefixes in config/sms.php.");
    }
}
