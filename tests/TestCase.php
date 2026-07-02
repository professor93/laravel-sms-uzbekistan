<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Uzbek\Sms\SmsServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            SmsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('cache.default', 'array');

            $config->set('sms.default', 'eskiz');
            // Both ship disabled by default; the suite exercises them.
            $config->set('sms.webhook.enabled', true);
            $config->set('sms.logging.database', true);
            $config->set('sms.drivers', [
                'eskiz' => [
                    'enabled' => true,
                    'base_url' => 'https://notify.eskiz.uz/api',
                    'email' => 'sender@example.test',
                    'password' => 'eskiz-password',
                    'from' => '4546',
                    'token_ttl' => 3600,
                    'http_options' => [],
                ],
                'playmobile' => [
                    'enabled' => true,
                    'base_url' => 'https://send.smsxabar.uz/broker-api',
                    'username' => 'acme',
                    'password' => 'playmobile-password',
                    'from' => '3700',
                    'webhook_secret' => 'hook-secret',
                    'allowed_ips' => [],
                    'http_options' => [],
                ],
                'textup' => [
                    'enabled' => true,
                    'base_url' => 'https://sms-api.textup.uz/v1',
                    'auth_url' => 'https://api-auth.textup.uz/v1',
                    'email' => 'sender@example.test',
                    'password' => 'textup-password',
                    'user_id' => 'user-uuid-1',
                    'template_id' => 'tpl-uuid-1',
                    'from' => null,
                    'token_ttl' => 3600,
                    'is_otp' => false,
                    'http_options' => [],
                ],
                'sayqal' => [
                    'enabled' => true,
                    'base_url' => 'https://routee.sayqal.uz/sms',
                    'username' => 'acme',
                    'secret_key' => 'sayqal-secret',
                    'service_id' => 7,
                    'from' => 'ACME',
                    'http_options' => [],
                ],
            ]);
        });
    }
}
