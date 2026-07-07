<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Facades\EskizSms;
use Uzbek\Sms\Facades\PlayMobileSms;
use Uzbek\Sms\Facades\SayqalSms;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Facades\TextUpSms;

it('sends through a per-driver facade', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 7, 'status' => 'waiting']),
    ]);

    $message = EskizSms::send('+998901234567', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->provider)->toBe('eskiz')
        ->and($message->providerMessageId)->toBe('7');
});

it('resolves the same instance the factory returns', function () {
    expect(EskizSms::getFacadeRoot())->toBe(app(DriverFactory::class)->make('eskiz'));
});

it('maps every facade to its driver', function () {
    expect(EskizSms::name())->toBe('eskiz')
        ->and(PlayMobileSms::name())->toBe('playmobile')
        ->and(TextUpSms::name())->toBe('textup')
        ->and(SayqalSms::name())->toBe('sayqal');
});

it('exposes the fluent builder through facades', function () {
    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 5])]);

    $message = SayqalSms::to('+998931234567')->text('Salom')->send();

    expect($message->successful)->toBeTrue()
        ->and($message->provider)->toBe('sayqal');
});

it('pulls delivery status through facades', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/status_by_id/*' => Http::response(['data' => ['status' => 'DELIVRD']]),
    ]);

    expect(EskizSms::checkStatus('7'))->toBe(DeliveryStatus::Delivered);
});

it('proxies the default driver through Sms', function () {
    expect(Sms::name())->toBe('eskiz');
});

it('follows the configured default driver', function () {
    config()->set('sms.default', 'sayqal');

    expect(Sms::name())->toBe('sayqal');
});

it('fails fast when the driver behind a facade is disabled', function () {
    config()->set('sms.providers.eskiz.enabled', false);

    EskizSms::name();
})->throws(DriverDisabledException::class);

it('binds a container entry per configured provider', function () {
    expect(app('sms.provider.eskiz'))->toBe(app(DriverFactory::class)->make('eskiz'))
        ->and(app('sms.provider.sayqal'))->toBe(app(DriverFactory::class)->make('sayqal'));
});
