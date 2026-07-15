<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Uzbek\Sms\Data\HealthStatus;

/**
 * A driver's own built-in health probe. Overridable per provider with the
 * `health_check` config key (see HealthCheck).
 */
interface ChecksHealth
{
    public function healthy(): HealthStatus;
}
