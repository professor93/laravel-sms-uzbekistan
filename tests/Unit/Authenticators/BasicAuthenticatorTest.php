<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Authenticators\BasicAuthenticator;

it('applies basic credentials to the outgoing request', function () {
    Http::fake();

    $authenticator = new BasicAuthenticator('user', 'pass');

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Basic '.base64_encode('user:pass'));
});
