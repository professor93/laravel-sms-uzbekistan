<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Throwable;
use Uzbek\Sms\Contracts\ProviderConfigOverrides;

final class ArrayConfigOverrides implements ProviderConfigOverrides
{
    /** @var array<string, array<string, mixed>> */
    public static array $overrides = [];

    public static ?Throwable $throws = null;

    public static function reset(): void
    {
        self::$overrides = [];
        self::$throws = null;
    }

    public function overrides(string $provider): array
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$overrides[$provider] ?? [];
    }
}
