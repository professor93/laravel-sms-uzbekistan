<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\HealthCheck;
use Uzbek\Sms\Data\HealthStatus;

final class FakeHealthCheck implements HealthCheck
{
    public static ?HealthStatus $status = null;

    public function check(Driver $driver): HealthStatus
    {
        return self::$status ?? HealthStatus::ok();
    }
}
