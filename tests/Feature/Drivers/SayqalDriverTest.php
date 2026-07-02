<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\DeliveryStatus;
use Uzbek\Sms\Events\SmsSent;

function sayqal(): Driver
{
    return app(DriverFactory::class)->make('sayqal');
}

it('sends a single sms with a per-request signed token', function () {
    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 1093, 'smsid' => 'echo'])]);

    $message = sayqal()->send('+998 93 123-45-67', 'Salom');

    expect($message->successful)->toBeTrue()
        ->and($message->phone)->toBe('998931234567')
        ->and($message->providerMessageId)->toStartWith('1093:');

    Http::assertSent(function (Request $request): bool {
        $expected = md5(sprintf('TransmitSMS %s %s %s', 'acme', 'sayqal-secret', $request['utime']));

        return $request->header('X-Access-Token')[0] === $expected
            && $request['username'] === 'acme'
            && $request['service']['service'] === 7
            && $request['service']['nickname'] === 'ACME'
            && $request['message']['phone'] === '998931234567'
            && $request['message']['text'] === 'Salom';
    });
});

it('loops sendMany through single requests — no bulk endpoint', function () {
    Event::fake([SmsSent::class]);

    Http::fake(['routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 1093])]);

    $results = sayqal()->sendMany(OutboundMessage::sameText(
        ['+998931111111', '+998932222222', '+998933333333'],
        'Bir xil matn',
    ));

    expect($results)->toHaveCount(3)
        ->and($results->every(fn ($m): bool => $m->successful))->toBeTrue()
        ->and($results->pluck('providerMessageId')->unique())->toHaveCount(3);

    Http::assertSentCount(3);

    Event::assertDispatchedTimes(SmsSent::class, 3);
});

it('splits the composite id to pull status with both identifiers', function () {
    Http::fake(['routee.sayqal.uz/sms/StatusSMS' => Http::response(['smsid' => 'ULID1', 'status' => 0])]);

    $driver = sayqal();

    expect($driver)->toBeInstanceOf(ChecksDeliveryStatus::class)
        ->and($driver->checkStatus('1093:ULID1'))->toBe(DeliveryStatus::Delivered);

    Http::assertSent(fn (Request $request): bool => $request['transactionid'] === 1093
        && $request['smsid'] === 'ULID1'
        && $request->header('X-Access-Token')[0] === md5(sprintf('StatusSMS %s %s %s', 'acme', 'sayqal-secret', $request['utime'])));
});

it('maps provider status codes onto the shared enum', function (int $code, DeliveryStatus $expected) {
    Http::fake(['routee.sayqal.uz/sms/StatusSMS' => Http::response(['status' => $code])]);

    expect(sayqal()->checkStatus('1:ULID'))->toBe($expected);
})->with([
    [0, DeliveryStatus::Delivered],
    [1, DeliveryStatus::Undelivered],
    [2, DeliveryStatus::Undelivered],
    [3, DeliveryStatus::Sent],
    [4, DeliveryStatus::Sent],
    [5, DeliveryStatus::Unknown],
]);
