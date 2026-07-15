<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Uzbek\Sms\Contracts\PrefixRules;

final class BlockEverythingPrefixRules implements PrefixRules
{
    public function allowlist(string $provider): array
    {
        return [];
    }

    public function blocklist(string $provider): array
    {
        return ['998'];
    }
}
