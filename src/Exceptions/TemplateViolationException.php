<?php

declare(strict_types=1);

namespace Uzbek\Sms\Exceptions;

final class TemplateViolationException extends SmsException
{
    public static function make(string $text): self
    {
        return new self(sprintf(
            'SMS text [%s] matches no template in sms.templates.list while enforcement is on.',
            mb_strimwidth($text, 0, 60, '...'),
        ));
    }
}
