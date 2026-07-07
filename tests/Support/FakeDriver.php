<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Uzbek\Sms\Authenticators\ApiKeyAuthenticator;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Drivers\AbstractDriver;

final class FakeDriver extends AbstractDriver
{
    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        return new ApiKeyAuthenticator('X-Fake-Key', 'fake');
    }

    protected function doSend(string $phone, string $text): SentMessage
    {
        return SentMessage::success($this->name(), $phone, $text, 'fake-1');
    }
}
