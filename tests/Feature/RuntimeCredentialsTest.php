<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;

it('sends with runtime credentials instead of the configured ones', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-runtime']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    app(DriverFactory::class)
        ->make('eskiz', ['email' => 'runtime@acme.uz', 'password' => 'runtime-secret'])
        ->send('+998901234567', 'Salom');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'auth/login')) {
            return true;
        }

        return $request['email'] === 'runtime@acme.uz'
            && $request['password'] === 'runtime-secret';
    });
});

it('caches the runtime token under a credential-hashed key, separate from config', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-runtime']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    app(DriverFactory::class)
        ->make('eskiz', ['email' => 'runtime@acme.uz', 'password' => 'runtime-secret'])
        ->send('+998901234567', 'Salom');

    $fingerprint = substr(md5((string) json_encode([
        'email' => 'runtime@acme.uz',
        'password' => 'runtime-secret',
    ])), 0, 12);

    // Default config token key stays untouched; the runtime token lives under a suffixed key.
    expect(Cache::get('sms:eskiz:token'))->toBeNull()
        ->and(Cache::get("sms:eskiz:token:{$fingerprint}"))->toBe('jwt-runtime');
});

it('does not reuse a token across different credential sets', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::sequence()
            ->push(['data' => ['token' => 'token-a']])
            ->push(['data' => ['token' => 'token-b']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $factory = app(DriverFactory::class);
    $factory->make('eskiz', ['email' => 'a@acme.uz', 'password' => 'aaa'])->send('+998901111111', 'A');
    $factory->make('eskiz', ['email' => 'b@acme.uz', 'password' => 'bbb'])->send('+998902222222', 'B');

    $logins = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r): bool => str_contains($r->url(), 'auth/login'));

    expect($logins)->toHaveCount(2)
        ->and($logins->first()['email'])->toBe('a@acme.uz')
        ->and($logins->last()['email'])->toBe('b@acme.uz');

    $sends = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r): bool => str_contains($r->url(), 'message/sms/send'));

    expect($sends->first()->header('Authorization')[0])->toBe('Bearer token-a')
        ->and($sends->last()->header('Authorization')[0])->toBe('Bearer token-b');
});

it('reuses the cached token for repeated calls with the same credentials', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    $factory = app(DriverFactory::class);
    $creds = ['email' => 'same@acme.uz', 'password' => 'same'];
    $factory->make('eskiz', $creds)->send('+998901111111', 'A');
    $factory->make('eskiz', $creds)->send('+998902222222', 'B');

    $logins = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r): bool => str_contains($r->url(), 'auth/login'));

    expect($logins)->toHaveCount(1);
});

it('exposes runtime credentials through the sms() helper', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 9, 'status' => 'waiting']),
    ]);

    $message = sms('eskiz', ['email' => 'helper@acme.uz', 'password' => 'pw'])->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'auth/login')
        || $request['email'] === 'helper@acme.uz');
});

it('still fails fast for a disabled driver even with runtime credentials', function () {
    config()->set('sms.providers.eskiz.enabled', false);

    app(DriverFactory::class)->make('eskiz', ['email' => 'x@acme.uz', 'password' => 'y']);
})->throws(\Uzbek\Sms\Exceptions\DriverDisabledException::class);

it('does not crash when an override value is not serializable', function () {
    $driver = app(DriverFactory::class)->make('eskiz', [
        'http_options' => ['on_stats' => fn () => null],
    ]);

    expect($driver)->toBeInstanceOf(\Uzbek\Sms\Drivers\EskizDriver::class);
});

it('uses the captured userId for overridden textup credentials, not the base config id', function () {
    config()->set('sms.providers.textup.user_id', 'base-account-id');

    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-t', 'user' => ['id' => 'tenant-id-42']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    sms('textup', ['email' => 'tenant@acme.uz', 'password' => 'tenant-pw'])->send('+998901234567', 'Salom');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['userId'] === 'tenant-id-42';
    });
});
