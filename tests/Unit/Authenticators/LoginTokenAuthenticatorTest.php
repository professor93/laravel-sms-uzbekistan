<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Authenticators\LoginTokenAuthenticator;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Tests\Support\LocklessArrayStore;

function loginAuthenticator(mixed $cache, int &$calls): LoginTokenAuthenticator
{
    return new LoginTokenAuthenticator(
        cache: $cache,
        cacheKey: 'sms:test:token',
        login: function () use (&$calls): string {
            $calls++;

            return 'login-'.$calls;
        },
        ttl: 3600,
    );
}

it('logs in lazily on first apply and caches the token', function () {
    Http::fake();

    $calls = 0;
    $authenticator = loginAuthenticator(Cache::store('array'), $calls);

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');
    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    expect($calls)->toBe(1)
        ->and(Cache::store('array')->get('sms:test:token'))->toBe('login-1');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer login-1');
});

it('adopts a token refreshed by a sibling without logging in', function () {
    Http::fake();

    $cache = Cache::store('array');
    $calls = 0;
    $authenticator = loginAuthenticator($cache, $calls);

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');
    $cache->put('sms:test:token', 'sibling-token', 3600);

    $authenticator->refresh();

    expect($calls)->toBe(1);

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer sibling-token');
});

it('logs in exactly once when the cached token is still the stale one', function () {
    Http::fake();

    $cache = Cache::store('array');
    $calls = 0;
    $authenticator = loginAuthenticator($cache, $calls);

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    $authenticator->refresh();

    expect($calls)->toBe(2)
        ->and($cache->get('sms:test:token'))->toBe('login-2');
});

it('logs in on refresh when the cache is empty', function () {
    $cache = Cache::store('array');
    $calls = 0;
    $authenticator = loginAuthenticator($cache, $calls);

    $authenticator->refresh();

    expect($calls)->toBe(1)
        ->and($cache->get('sms:test:token'))->toBe('login-1');
});

it('degrades to a lock-less login when the store has no lock support', function () {
    $cache = new Repository(new LocklessArrayStore);
    $calls = 0;
    $authenticator = loginAuthenticator($cache, $calls);

    $authenticator->refresh();

    expect($calls)->toBe(1)
        ->and($cache->get('sms:test:token'))->toBe('login-1');
});

it('stores tokens under the configured cache prefix', function () {
    config()->set('sms.cache.prefix', 'custom');

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect(Cache::get('custom:eskiz:token'))->toBe('jwt-1');
});
