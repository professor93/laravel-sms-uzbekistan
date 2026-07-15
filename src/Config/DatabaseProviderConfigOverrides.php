<?php

declare(strict_types=1);

namespace Uzbek\Sms\Config;

use Throwable;
use Uzbek\Sms\Contracts\ProviderConfigOverrides;
use Uzbek\Sms\Models\SmsProviderOverride;

/**
 * Reads override rows from sms_provider_overrides (publish the
 * sms-overrides-migration tag first), cached for config_overrides_ttl
 * seconds. A missing table or broken connection yields no overrides.
 */
final class DatabaseProviderConfigOverrides implements ProviderConfigOverrides
{
    public function overrides(string $provider): array
    {
        try {
            return cache()->store(config('sms.cache.store'))->remember(
                self::cacheKey($provider),
                max(1, (int) config('sms.config_overrides_ttl', 60)),
                fn (): array => (array) (SmsProviderOverride::query()->where('provider', $provider)->first()?->config ?? []),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Call after editing a row so the change applies before the TTL runs out.
     */
    public static function flush(string $provider): void
    {
        try {
            cache()->store(config('sms.cache.store'))->forget(self::cacheKey($provider));
        } catch (Throwable) {
            // Nothing to flush.
        }
    }

    private static function cacheKey(string $provider): string
    {
        return sprintf('%s:config-overrides:%s', config('sms.cache.prefix', 'sms'), $provider);
    }
}
