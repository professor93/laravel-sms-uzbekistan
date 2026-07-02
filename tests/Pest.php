<?php

declare(strict_types=1);

use Uzbek\Sms\Tests\DatabaseLogOffTestCase;
use Uzbek\Sms\Tests\DebugLoggingTestCase;
use Uzbek\Sms\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(DebugLoggingTestCase::class)->in('Logging/Debug');
uses(DatabaseLogOffTestCase::class)->in('Logging/DatabaseOff');
