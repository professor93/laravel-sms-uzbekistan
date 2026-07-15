<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Illuminate\Http\Request;
use Uzbek\Sms\Data\DeliveryReport;

interface WebhookHandler
{
    /**
     * Handle a webhook request for a provider. The request has already passed
     * the driver's own verification when the driver implements HandlesWebhooks.
     *
     * @param  list<DeliveryReport>  $reports  parsed delivery reports; empty when
     *                                         the driver cannot parse webhooks
     */
    public function handle(Request $request, Driver $driver, array $reports): void;
}
