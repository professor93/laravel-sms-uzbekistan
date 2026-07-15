<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Uzbek\Sms\Data\HealthStatus;

/**
 * External health probe registered per provider via the `health_check`
 * config key; takes precedence over the driver's own ChecksHealth.
 */
interface HealthCheck
{
    public function check(Driver $driver): HealthStatus;
}
