<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

/**
 * Runtime source of per-provider config overrides. The published file config
 * stays the base; a source returns ONLY the keys that should differ (e.g.
 * rotated credentials, a toggled `enabled`) — never full provider blocks.
 */
interface ProviderConfigOverrides
{
    /**
     * @return array<string, mixed>
     */
    public function overrides(string $provider): array;
}
