<?php

declare(strict_types=1);

use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\DriverFactory;

if (! function_exists('sms')) {
    /**
     * @param  array<string, mixed>  $config  runtime overrides (e.g. custom email/password)
     */
    function sms(?string $provider = null, array $config = []): Driver
    {
        $factory = app(DriverFactory::class);

        if ($provider === null && $config === []) {
            return $factory->default();
        }

        return $factory->make($provider ?? (string) config('sms.default'), $config);
    }
}
