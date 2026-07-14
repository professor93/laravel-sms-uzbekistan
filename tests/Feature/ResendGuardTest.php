<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Exceptions\DriverDisabledException;

it('throws on a second send() and does not resend', function () {
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $pending = sms('sayqal')->to('+998901234567')->text('Salom');

    $pending->send();

    expect(fn () => $pending->send())->toThrow(LogicException::class, 'Message already sent.');

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'TransmitSMS')))->toHaveCount(1);
});

it('throws on a second bulk send() and does not resend', function () {
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $pending = sms('sayqal')->many(OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom'));

    $pending->send();

    expect(fn () => $pending->send())->toThrow(LogicException::class, 'Messages already sent.');

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'TransmitSMS')))->toHaveCount(2);
});

it('stays reusable when the overridden driver cannot be resolved', function () {
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $pending = sms('sayqal')->to('+998901234567')->text('Salom')->as(['username' => 'u2', 'secret_key' => 's2']);

    config()->set('sms.providers.sayqal.enabled', false);

    expect(fn () => $pending->send())->toThrow(DriverDisabledException::class);

    config()->set('sms.providers.sayqal.enabled', true);

    expect($pending->send()->successful)->toBeTrue();
});

it('stays reusable after a validation failure', function () {
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $pending = sms('sayqal')->to('+998901234567');

    expect(fn () => $pending->send())->toThrow(LogicException::class, 'No text set.');

    $result = $pending->text('Salom')->send();

    expect($result->successful)->toBeTrue();
});
