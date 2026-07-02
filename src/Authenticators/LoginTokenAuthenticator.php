<?php

declare(strict_types=1);

namespace Uzbek\Sms\Authenticators;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\PendingRequest;
use Uzbek\Sms\Contracts\Authenticator;

final class LoginTokenAuthenticator implements Authenticator
{
    private const LOCK_TTL = 10;

    private const LOCK_WAIT = 10;

    private ?string $current = null;

    /**
     * @param  Closure(): string  $login
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $cacheKey,
        private readonly Closure $login,
        private readonly int $ttl,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->token());
    }

    public function refresh(): void
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $store->lock($this->cacheKey.':lock', self::LOCK_TTL)
                ->block(self::LOCK_WAIT, fn () => $this->refreshInsideLock());

            return;
        }

        // No atomic locks: duplicate logins are harmless, lost tokens are not.
        $this->authenticate();
    }

    private function refreshInsideLock(): void
    {
        $cached = $this->cache->get($this->cacheKey);

        // A sibling already refreshed — adopt its token, skip the login.
        if (is_string($cached) && $cached !== $this->current) {
            $this->current = $cached;

            return;
        }

        $this->authenticate();
    }

    private function token(): string
    {
        $cached = $this->cache->get($this->cacheKey);

        if (is_string($cached)) {
            return $this->current = $cached;
        }

        return $this->authenticate();
    }

    private function authenticate(): string
    {
        $token = ($this->login)();

        $this->cache->put($this->cacheKey, $token, $this->ttl);

        return $this->current = $token;
    }
}
