<?php

declare(strict_types=1);

namespace Uzbek\Sms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Throwable;
use Uzbek\Sms\Contracts\HandlesWebhooks;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Exceptions\SmsException;

final class WebhookController
{
    public function __invoke(Request $request, DriverFactory $factory, string $provider): Response
    {
        try {
            $instance = $factory->make($provider);
        } catch (SmsException) {
            // Never leak configuration state on a public endpoint.
            abort(404);
        }

        abort_unless($instance instanceof HandlesWebhooks, 404);

        try {
            $instance->verifyWebhook($request);
        } catch (Throwable) {
            abort(403);
        }

        foreach ($instance->parseWebhook($request) as $report) {
            Event::dispatch(new DeliveryStatusUpdated(
                provider: $instance->name(),
                providerMessageId: $report->providerMessageId,
                status: $report->status,
                raw: $report->raw,
            ));
        }

        return new Response('OK');
    }
}
