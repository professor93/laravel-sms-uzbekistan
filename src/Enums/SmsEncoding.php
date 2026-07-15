<?php

declare(strict_types=1);

namespace Uzbek\Sms\Enums;

enum SmsEncoding: string
{
    case Gsm7 = 'gsm7';
    case Ucs2 = 'ucs2';
}
