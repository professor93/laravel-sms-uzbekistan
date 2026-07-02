<?php

declare(strict_types=1);

namespace Uzbek\Sms\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Uzbek\Sms\Data\SentMessage send(string $phone, string $text)
 * @method static \Illuminate\Support\Collection<int, \Uzbek\Sms\Data\SentMessage> sendMany(iterable $messages)
 * @method static \Uzbek\Sms\PendingMessage to(string $phone)
 * @method static \Uzbek\Sms\Enums\DeliveryStatus checkStatus(string $providerMessageId)
 * @method static string name()
 *
 * @see \Uzbek\Sms\Drivers\EskizDriver
 */
final class EskizSms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sms.driver.eskiz';
    }
}
