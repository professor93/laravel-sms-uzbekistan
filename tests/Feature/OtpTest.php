<?php

declare(strict_types=1);

use Uzbek\Sms\Enums\OtpStatus;
use Uzbek\Sms\Facades\Sms;

function lastOtpCode(): string
{
    preg_match('/(\d+)$/', Sms::sent()->last()->text, $m);

    return $m[1];
}

it('sends a templated code and verifies it', function () {
    Sms::fake();

    $message = Sms::otp()->send('+998901234567');

    expect($message->successful)->toBeTrue();

    Sms::assertSentCount(1);

    expect(Sms::sent()->first()->text)->toMatch('/^Tasdiqlash kodi: \d{6}$/');

    expect(Sms::otp()->verify('+998901234567', lastOtpCode()))->toBe(OtpStatus::Valid);
});

it('rejects a wrong code', function () {
    Sms::fake();

    Sms::otp()->send('+998901234567');

    expect(Sms::otp()->verify('+998901234567', '000000'))->toBe(OtpStatus::Invalid);
});

it('locks out after too many wrong attempts', function () {
    config()->set('sms.otp.max_attempts', 2);

    Sms::fake();

    Sms::otp()->send('+998901234567');
    $code = lastOtpCode();

    expect(Sms::otp()->verify('+998901234567', '000001'))->toBe(OtpStatus::Invalid)
        ->and(Sms::otp()->verify('+998901234567', '000002'))->toBe(OtpStatus::TooManyAttempts)
        ->and(Sms::otp()->verify('+998901234567', $code))->toBe(OtpStatus::Expired);
});

it('reports expired for an unknown phone', function () {
    Sms::fake();

    expect(Sms::otp()->verify('+998909999999', '123456'))->toBe(OtpStatus::Expired);
});

it('is single use', function () {
    Sms::fake();

    Sms::otp()->send('+998901234567');
    $code = lastOtpCode();

    expect(Sms::otp()->verify('+998901234567', $code))->toBe(OtpStatus::Valid)
        ->and(Sms::otp()->verify('+998901234567', $code))->toBe(OtpStatus::Expired);
});

it('enforces the resend cooldown', function () {
    Sms::fake();

    Sms::otp()->send('+998901234567');
    $second = Sms::otp()->send('+998901234567');

    expect($second->successful)->toBeFalse()
        ->and($second->errorMessage)->toContain('cooldown');

    Sms::assertSentCount(1);
});

it('honors custom length and template', function () {
    config()->set('sms.otp.length', 4);
    config()->set('sms.otp.template', 'Kod: :code');

    Sms::fake();

    Sms::otp()->send('+998901234567');

    expect(Sms::sent()->first()->text)->toMatch('/^Kod: \d{4}$/');
});

it('normalizes the phone between send and verify', function () {
    Sms::fake();

    Sms::otp()->send('+998 90 123-45-67');

    expect(Sms::otp()->verify('998901234567', lastOtpCode()))->toBe(OtpStatus::Valid);
});
