<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Throwable;
use Uzbek\Sms\Contracts\PrefixRules;

final class FakePrefixRules implements PrefixRules
{
    /** @var array<string, array{allowed?: list<string>, blocked?: list<string>}> */
    public static array $rules = [];

    /** Counted once per load cycle (allowlist + blocklist run back to back). */
    public static int $calls = 0;

    public static ?Throwable $throws = null;

    public static function reset(): void
    {
        self::$rules = [];
        self::$calls = 0;
        self::$throws = null;
    }

    public function allowlist(string $provider): array
    {
        self::$calls++;

        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$rules[$provider]['allowed'] ?? [];
    }

    public function blocklist(string $provider): array
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$rules[$provider]['blocked'] ?? [];
    }
}
