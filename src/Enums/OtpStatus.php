<?php

declare(strict_types=1);

namespace Uzbek\Sms\Enums;

enum OtpStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
}
