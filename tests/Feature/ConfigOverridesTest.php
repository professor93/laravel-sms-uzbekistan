<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Uzbek\Sms\Config\DatabaseProviderConfigOverrides;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Models\SmsProviderOverride;
use Uzbek\Sms\Tests\Support\ArrayConfigOverrides;

beforeEach(function (): void {
    ArrayConfigOverrides::reset();
});

function fakeEskizForOverrides(): void
{
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);
}

it('merges source overrides over the file config', function () {
    config()->set('sms.config_overrides', ArrayConfigOverrides::class);
    ArrayConfigOverrides::$overrides = ['eskiz' => ['from' => 'OVR']];

    fakeEskizForOverrides();

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['from'] === 'OVR');
});

it('lets runtime credentials beat the source overrides', function () {
    config()->set('sms.config_overrides', ArrayConfigOverrides::class);
    ArrayConfigOverrides::$overrides = ['eskiz' => ['from' => 'OVR']];

    fakeEskizForOverrides();

    app(DriverFactory::class)->make('eskiz', ['from' => 'RUNTIME'])->send('+998901234567', 'Salom');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['from'] === 'RUNTIME');
});

it('can disable a provider from the source', function () {
    config()->set('sms.config_overrides', ArrayConfigOverrides::class);
    ArrayConfigOverrides::$overrides = ['eskiz' => ['enabled' => false]];

    app(DriverFactory::class)->make('eskiz');
})->throws(DriverDisabledException::class);

it('falls back to the file config when the source throws', function () {
    config()->set('sms.config_overrides', ArrayConfigOverrides::class);
    ArrayConfigOverrides::$throws = new RuntimeException('db down');

    fakeEskizForOverrides();

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['from'] === '4546');
});

it('reads overrides from the database implementation', function () {
    Schema::create('sms_provider_overrides', function ($table): void {
        $table->id();
        $table->string('provider')->unique();
        $table->json('config');
        $table->timestamps();
    });

    SmsProviderOverride::query()->create(['provider' => 'eskiz', 'config' => ['from' => 'DBFROM']]);

    config()->set('sms.config_overrides', DatabaseProviderConfigOverrides::class);

    fakeEskizForOverrides();

    app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'message/sms/send')
        && $request['from'] === 'DBFROM');
});

it('degrades gracefully when the overrides table is missing', function () {
    config()->set('sms.config_overrides', DatabaseProviderConfigOverrides::class);

    fakeEskizForOverrides();

    $message = app(DriverFactory::class)->make('eskiz')->send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue();
});
