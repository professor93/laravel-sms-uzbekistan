<?php

declare(strict_types=1);

namespace Uzbek\Sms\Facades;

use Illuminate\Support\Facades\Facade;
use Uzbek\Sms\Contracts\Driver;

/**
 * @method static \Uzbek\Sms\Data\SentMessage send(string $phone, string $text)
 * @method static \Illuminate\Support\Collection<int, \Uzbek\Sms\Data\SentMessage> sendMany(iterable $messages, ?string $fallback = null, ?\Closure $fallbackWhen = null)
 * @method static \Uzbek\Sms\PendingMessage to(string $phone)
 * @method static \Uzbek\Sms\PendingBulkMessage many(iterable $messages)
 * @method static string name()
 *
 * @see \Uzbek\Sms\Contracts\Driver
 */
final class Sms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Driver::class;
    }
}
