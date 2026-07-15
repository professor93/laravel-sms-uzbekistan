<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

/**
 * Dynamic allow/block prefix lists for a provider, merged with the static
 * `prefixes` config lists before every send. Neither list is required:
 * an empty allowlist means no allow-restriction, the blocklist always wins.
 * Cache internally if the lookup is expensive — this runs once per send call.
 */
interface PrefixRules
{
    /**
     * @return list<string>
     */
    public function allowlist(string $provider): array;

    /**
     * @return list<string>
     */
    public function blocklist(string $provider): array;
}
