<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\HandlesWebhooks;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Models\SmsLog;

function textup(): Driver
{
    return app(DriverFactory::class)->make('textup');
}

it('sends a single sms with E.164 phone and required userId', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1', 'refreshToken' => 'r']),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-100']),
    ]);

    $message = textup()->send('998 90 001-00-01', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->phone)->toBe('+998900010001')
        ->and($message->providerMessageId)->toBe('sms-100');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['recipients'] === ['+998900010001']
            && $request['userId'] === 'user-uuid-1'
            && $request['message'] === 'Salom'
            && $request->header('Authorization')[0] === 'Bearer jwt-1';
    });
});

it('captures userId from the login response and uses it when none is configured', function () {
    config()->set('sms.providers.textup.user_id', null);

    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response([
            'accessToken' => 'jwt-1',
            'user' => ['id' => 'captured-uuid-9'],
        ]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    textup()->send('+998901234567', 'Salom');

    expect(Cache::get('sms:textup:token:user'))->toBe('captured-uuid-9');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['userId'] === 'captured-uuid-9';
    });
});

it('prefers a configured userId over the captured one', function () {
    config()->set('sms.providers.textup.user_id', 'configured-uuid');

    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response([
            'accessToken' => 'jwt-1',
            'user' => ['id' => 'captured-uuid-9'],
        ]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    textup()->send('+998901234567', 'Salom');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['userId'] === 'configured-uuid';
    });
});

it('sets isOtp per send via a runtime override without rotating the token', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1', 'user' => ['id' => 'u1']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    sms('textup', ['is_otp' => true])->send('+998901234567', 'code 1234');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return $request['isOtp'] === true;
    });

    // A non-credential override reuses the configured account's token key.
    expect(Cache::get('sms:textup:token'))->toBe('jwt-1');
});

it('omits isOtp by default', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);

    textup()->send('+998901234567', 'Salom');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'sms-api.textup.uz')) {
            return true;
        }

        return ! array_key_exists('isOtp', (array) $request->data());
    });
});

it('batches identical texts into one request with composite ids', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'job-1']),
    ]);

    $results = textup()->sendMany(OutboundMessage::sameText(
        ['+998901111111', '+998902222222', '+998903333333'],
        'Bir xil matn',
    ));

    expect($results)->toHaveCount(3)
        ->and($results->pluck('providerMessageId')->all())->toBe([
            'job-1:+998901111111',
            'job-1:+998902222222',
            'job-1:+998903333333',
        ]);

    $sends = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'sms-api.textup.uz'));

    expect($sends)->toHaveCount(1);

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('survives a duplicated recipient in one bulk without a unique violation', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'job-9']),
    ]);

    $results = textup()->sendMany(OutboundMessage::sameText(['+998901111111', '+998901111111'], 'Salom'));

    expect($results)->toHaveCount(2)
        ->and(SmsLog::query()->count())->toBe(1); // same composite id upserts one row
});

it('persists bulk rows with distinct composite ids without unique violations', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'job-2']),
    ]);

    textup()->sendMany(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'));

    expect(SmsLog::query()->count())->toBe(2)
        ->and(SmsLog::query()->pluck('provider_message_id')->unique())->toHaveCount(2);
});

it('falls back to one request per message for mixed texts', function () {
    Event::fake([SmsSent::class]);

    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/send' => Http::sequence()
            ->push(['smsId' => 'sms-1'])
            ->push(['smsId' => 'sms-2'])
            ->push(['smsId' => 'sms-3']),
    ]);

    $results = textup()->sendMany([
        new OutboundMessage('+998901111111', 'Birinchi'),
        new OutboundMessage('+998902222222', 'Ikkinchi'),
        new OutboundMessage('+998903333333', 'Uchinchi'),
    ]);

    expect($results)->toHaveCount(3)
        ->and($results->pluck('providerMessageId')->all())->toBe(['sms-1', 'sms-2', 'sms-3']);

    $sends = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'sms-api.textup.uz'));

    expect($sends)->toHaveCount(3);

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('strips the composite suffix before pulling status', function () {
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-1']),
        'sms-api.textup.uz/v1/sms/*' => Http::response(['status' => 'delivered']),
    ]);

    $driver = textup();

    expect($driver)->toBeInstanceOf(ChecksDeliveryStatus::class)
        ->and($driver->checkStatus('job-1:+998901111111'))->toBe(DeliveryStatus::Delivered);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sms/')) {
            return true;
        }

        return str_ends_with($request->url(), '/sms/job-1');
    });
});

it('does not pretend to handle webhooks', function () {
    expect(textup())->not->toBeInstanceOf(HandlesWebhooks::class);
});
