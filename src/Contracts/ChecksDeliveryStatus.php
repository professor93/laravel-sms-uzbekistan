<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Uzbek\Sms\Enums\DeliveryStatus;

interface ChecksDeliveryStatus
{
    public function checkStatus(string $providerMessageId): DeliveryStatus;
}
