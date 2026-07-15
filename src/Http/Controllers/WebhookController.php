<?php

declare(strict_types=1);

namespace Uzbek\Sms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\HandlesWebhooks;
use Uzbek\Sms\Contracts\WebhookHandler;
use Uzbek\Sms\Data\DeliveryReport;
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

        $handlerClass = config("sms.providers.{$provider}.webhook_handler");

        // A configured handler unlocks webhooks for drivers that cannot parse
        // them; verification (and security) is then the handler's concern.
        abort_unless(is_string($handlerClass) || $instance instanceof HandlesWebhooks, 404);

        $reports = [];

        if ($instance instanceof HandlesWebhooks) {
            try {
                $instance->verifyWebhook($request);
            } catch (Throwable) {
                abort(403);
            }

            /** @var list<DeliveryReport> $reports */
            $reports = Collection::make($instance->parseWebhook($request))->values()->all();
        }

        foreach ($reports as $report) {
            Event::dispatch(new DeliveryStatusUpdated(
                provider: $instance->name(),
                providerMessageId: $report->providerMessageId,
                status: $report->status,
                raw: $report->raw,
            ));
        }

        if (is_string($handlerClass)) {
            $this->handler($handlerClass)->handle($request, $instance, $reports);
        } else {
            $this->logUnhandled($instance, $request, $reports);
        }

        return new Response('OK');
    }

    private function handler(string $class): WebhookHandler
    {
        $handler = app($class);

        if (! $handler instanceof WebhookHandler) {
            throw new SmsException(sprintf(
                'Webhook handler [%s] must implement [%s].',
                $class,
                WebhookHandler::class,
            ));
        }

        return $handler;
    }

    /**
     * @param  list<DeliveryReport>  $reports
     */
    private function logUnhandled(Driver $instance, Request $request, array $reports): void
    {
        Log::channel(config('sms.logging.channel'))->info(
            sprintf('SMS webhook received for [%s]; no handler configured.', $instance->name()),
            [
                'provider' => $instance->name(),
                'reports' => array_map(fn (DeliveryReport $report): array => [
                    'provider_message_id' => $report->providerMessageId,
                    'status' => $report->status->value,
                ], $reports),
                'payload' => $request->all(),
            ],
        );
    }
}
