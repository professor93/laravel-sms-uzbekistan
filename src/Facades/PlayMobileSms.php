<?php

declare(strict_types=1);

namespace Uzbek\Sms\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Uzbek\Sms\Data\SentMessage send(string $phone, string $text)
 * @method static \Illuminate\Support\Collection<int, \Uzbek\Sms\Data\SentMessage> sendMany(iterable $messages, ?string $fallback = null, ?\Closure $fallbackWhen = null)
 * @method static \Uzbek\Sms\PendingMessage to(string $phone)
 * @method static \Uzbek\Sms\PendingBulkMessage many(iterable $messages)
 * @method static void verifyWebhook(\Illuminate\Http\Request $request)
 * @method static iterable parseWebhook(\Illuminate\Http\Request $request)
 * @method static string name()
 *
 * @see \Uzbek\Sms\Drivers\PlayMobileDriver
 */
final class PlayMobileSms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sms.provider.playmobile';
    }
}
