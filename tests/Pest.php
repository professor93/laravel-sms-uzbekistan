<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Tests\DatabaseLogOffTestCase;
use Uzbek\Sms\Tests\DebugLoggingTestCase;
use Uzbek\Sms\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(DebugLoggingTestCase::class)->in('Logging/Debug');
uses(DatabaseLogOffTestCase::class)->in('Logging/DatabaseOff');

function fakeTextUpSuccess(): void
{
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-secret', 'user' => ['id' => 'u1']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);
}
