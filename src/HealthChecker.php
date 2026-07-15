<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Throwable;
use Uzbek\Sms\Contracts\ChecksHealth;
use Uzbek\Sms\Contracts\HealthCheck;
use Uzbek\Sms\Data\HealthStatus;

/**
 * Never throws: an unknown provider, a broken check class, or a probe
 * exception all come back as a failed HealthStatus — monitoring code must
 * not need its own try/catch.
 */
final class HealthChecker
{
    public function __construct(private readonly DriverFactory $factory) {}

    public function check(string $provider): HealthStatus
    {
        try {
            $driver = $this->factory->make($provider);

            $class = config("sms.providers.{$provider}.health_check");

            if (is_string($class) && $class !== '') {
                $check = app($class);

                if (! $check instanceof HealthCheck) {
                    return HealthStatus::failed(sprintf('[%s] does not implement [%s].', $class, HealthCheck::class));
                }

                return $check->check($driver);
            }

            if ($driver instanceof ChecksHealth) {
                return $driver->healthy();
            }

            return HealthStatus::unknown();
        } catch (Throwable $e) {
            return HealthStatus::failed($e->getMessage());
        }
    }

    /**
     * @return array<string, HealthStatus>
     */
    public function checkAll(): array
    {
        $statuses = [];

        foreach (array_keys((array) config('sms.providers', [])) as $provider) {
            $statuses[$provider] = $this->check((string) $provider);
        }

        return $statuses;
    }
}
