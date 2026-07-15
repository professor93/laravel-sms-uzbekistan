<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Uzbek\Sms\Data\Balance;

interface ChecksBalance
{
    public function balance(): Balance;
}
