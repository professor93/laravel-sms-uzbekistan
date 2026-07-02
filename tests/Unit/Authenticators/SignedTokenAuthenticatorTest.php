<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Uzbek\Sms\Authenticators\SignedTokenAuthenticator;

it('signs the final outgoing request with the computed token', function () {
    Http::fake();

    $authenticator = new SignedTokenAuthenticator('X-Access-Token', function (RequestInterface $request): string {
        $action = basename($request->getUri()->getPath());
        $body = (array) json_decode((string) $request->getBody(), true);

        return md5(sprintf('%s %s %s %s', $action, 'demo', 'sekret', $body['utime']));
    });

    $authenticator->apply(Http::asJson())->post('https://routee.sayqal.uz/sms/TransmitSMS', [
        'utime' => 1720000000,
    ]);

    // md5('TransmitSMS demo sekret 1720000000')
    Http::assertSent(fn (Request $request): bool => $request->header('X-Access-Token')[0] === 'b491b6fbc4087d546ab20c50b890cf3c');
});

it('treats refresh as a no-op', function () {
    $authenticator = new SignedTokenAuthenticator('X-Access-Token', fn (): string => 'irrelevant');

    $authenticator->refresh();

    expect(true)->toBeTrue();
});
