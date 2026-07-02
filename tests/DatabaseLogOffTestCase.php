<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests;

abstract class DatabaseLogOffTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sms.logging.database', false);
    }
}
