<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Exceptions\UnknownProviderException;

it('applies otp() as an is_otp override on the outgoing request', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1', 'user' => ['id' => 'u1']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    sms('textup')->to('+998901234567')->text('code 1234')->otp()->send();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['isOtp'] === true;
    });
});

it('applies from() as a sender override', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
    ]);

    sms('eskiz')->to('+998901234567')->text('Salom')->from('MyBrand')->send();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'message/sms/send')) {
            return true;
        }

        return $request['from'] === 'MyBrand';
    });
});

it('falls back to the secondary provider when the primary fails', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    $result = sms('eskiz')->to('+998 90 123 45 67')->text('Salom')->useFallback('playmobile')->send();

    expect($result->successful)->toBeTrue()
        ->and($result->provider)->toBe('playmobile');

    Event::assertDispatchedTimes(SmsSent::class, 2);
});

it('does not contact the fallback when the primary succeeds', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->useFallback('playmobile')->send();

    expect($result->successful)->toBeTrue()
        ->and($result->provider)->toBe('eskiz');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'smsxabar.uz'));
    Event::assertDispatchedTimes(SmsSent::class, 1);
});

it('exposes isOtp(), nickname() and usingCredentials() aliases', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1', 'user' => ['id' => 'u1']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    sms('textup')
        ->to('+998901234567')
        ->text('code 1234')
        ->isOtp()
        ->nickname('Brand')
        ->usingCredentials(['email' => 'alias@acme.uz', 'password' => 'pw'])
        ->send();

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), 'api-auth.textup.uz')) {
            return $request['email'] === 'alias@acme.uz';
        }

        if (str_contains($request->url(), 'sms-api.textup.uz')) {
            return $request['isOtp'] === true && $request['nicknameId'] === 'Brand';
        }

        return true;
    });
});

it('honors a predicate that forces fallback even when the primary succeeds', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 1, 'status' => 'waiting']),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    $result = sms('eskiz')->to('+998901234567')->text('Salom')
        ->useFallback('playmobile', fn (SentMessage $sent): bool => true)
        ->send();

    expect($result->provider)->toBe('playmobile');
});

it('returns the fallback failed result when both providers fail', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(['error' => 'down'], 500),
    ]);

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->useFallback('playmobile')->send();

    expect($result->successful)->toBeFalse()
        ->and($result->provider)->toBe('playmobile');
});

it('does not leak primary overrides to the fallback', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    sms('eskiz')->to('+998901234567')->text('Salom')
        ->from('CustomBrand')
        ->useFallback('playmobile')
        ->send();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'smsxabar.uz')) {
            return true;
        }

        return $request['messages'][0]['sms']['originator'] === '3700';
    });
});

it('throws for an unknown fallback driver when the fallback is triggered', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    sms('eskiz')->to('+998901234567')->text('Salom')->useFallback('nexmo')->send();
})->throws(UnknownProviderException::class);
