<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Authenticators\BearerTokenAuthenticator;

it('applies a static bearer token to the outgoing request', function () {
    Http::fake();

    $authenticator = new BearerTokenAuthenticator('static-token');

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer static-token');
});
