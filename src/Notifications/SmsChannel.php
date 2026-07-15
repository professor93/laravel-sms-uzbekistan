<?php

declare(strict_types=1);

namespace Uzbek\Sms\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;

final class SmsChannel
{
    public function __construct(private readonly DriverFactory $factory) {}

    /**
     * Missing toSms() or missing route skips the notification with a warning
     * instead of erroring — notifications must not take the app down.
     */
    public function send(mixed $notifiable, Notification $notification): ?SentMessage
    {
        if (! method_exists($notification, 'toSms')) {
            $this->warn(sprintf('[%s] has no toSms() method; skipped.', $notification::class));

            return null;
        }

        $message = $notification->toSms($notifiable);

        if (is_string($message)) {
            $message = SmsMessage::create($message);
        }

        if (! $message instanceof SmsMessage || $message->text === '') {
            $this->warn(sprintf('[%s] returned no usable SMS text; skipped.', $notification::class));

            return null;
        }

        $phone = $message->phone ?? $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($phone) || $phone === '') {
            $this->warn(sprintf('No sms route for [%s]; skipped.', $notification::class));

            return null;
        }

        $driver = $message->provider !== null
            ? $this->factory->make($message->provider)
            : $this->factory->default();

        $pending = $driver->to($phone)->text($message->text);

        if ($message->from !== null) {
            $pending->from($message->from);
        }

        if ($message->otp) {
            $pending->otp();
        }

        if (is_string($message->fallback)) {
            $pending->useFallback($message->fallback);
        } elseif ($message->fallback === false) {
            $pending->withoutFallback();
        }

        return $pending->send();
    }

    private function warn(string $message): void
    {
        if (! config('sms.silent')) {
            Log::channel(config('sms.logging.channel'))->warning('SMS notification: '.$message);
        }
    }
}
