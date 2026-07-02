<?php

declare(strict_types=1);

use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\DriverFactory;

if (! function_exists('sms')) {
    function sms(?string $driver = null): Driver
    {
        $factory = app(DriverFactory::class);

        return $driver === null ? $factory->default() : $factory->make($driver);
    }
}
