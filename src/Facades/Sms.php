<?php

declare(strict_types=1);

namespace Uzbek\Sms\Facades;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Testing\SmsFake;

/**
 * @method static \Uzbek\Sms\Data\SentMessage send(string $phone, string $text)
 * @method static \Illuminate\Support\Collection<int, \Uzbek\Sms\Data\SentMessage> sendMany(iterable $messages, ?string $fallback = null, ?\Closure $fallbackWhen = null)
 * @method static \Uzbek\Sms\PendingMessage to(string $phone)
 * @method static \Uzbek\Sms\PendingBulkMessage many(iterable $messages)
 * @method static string name()
 * @method static \Illuminate\Support\Collection<int, \Uzbek\Sms\Data\SentMessage> sent()
 * @method static void assertSent(?\Closure $callback = null)
 * @method static void assertSentCount(int $count)
 * @method static void assertNothingSent()
 * @method static void assertSentTo(string $phone)
 *
 * @see \Uzbek\Sms\Contracts\Driver
 * @see \Uzbek\Sms\Testing\SmsFake
 */
final class Sms extends Facade
{
    /**
     * @return \Uzbek\Sms\Data\HealthStatus|array<string, \Uzbek\Sms\Data\HealthStatus>
     */
    public static function health(?string $provider = null): \Uzbek\Sms\Data\HealthStatus|array
    {
        $checker = static::getFacadeApplication()->make(\Uzbek\Sms\HealthChecker::class);

        return $provider === null ? $checker->checkAll() : $checker->check($provider);
    }

    public static function stats(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $provider = null,
    ): \Uzbek\Sms\Data\StatsReport {
        return static::getFacadeApplication()->make(\Uzbek\Sms\Stats\SmsStats::class)->report($from, $to, $provider);
    }

    public static function otp(): \Uzbek\Sms\Otp\OtpBroker
    {
        return static::getFacadeApplication()->make(\Uzbek\Sms\Otp\OtpBroker::class);
    }

    public static function fake(): SmsFake
    {
        $app = static::getFacadeApplication();

        $fake = new SmsFake(
            $app->make(HttpFactory::class),
            $app->make(ConfigRepository::class),
            $app->make(CacheFactory::class),
        );

        $app->instance(DriverFactory::class, $fake);
        $app->instance(Driver::class, $fake);

        // Per-provider singletons and facades must resolve to the fake too.
        foreach (array_keys((array) $app->make(ConfigRepository::class)->get('sms.providers', [])) as $name) {
            $app->instance("sms.provider.{$name}", $fake->make($name));
            static::clearResolvedInstance("sms.provider.{$name}");
        }

        static::clearResolvedInstance(Driver::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Driver::class;
    }
}
