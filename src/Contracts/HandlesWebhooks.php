<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Illuminate\Http\Request;
use Uzbek\Sms\Data\DeliveryReport;

interface HandlesWebhooks
{
    /**
     * @throws \Uzbek\Sms\Exceptions\SmsException
     */
    public function verifyWebhook(Request $request): void;

    /**
     * @return iterable<DeliveryReport>
     */
    public function parseWebhook(Request $request): iterable;
}
