<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Authenticators\ApiKeyAuthenticator;

it('applies the key under the configured header name', function () {
    Http::fake();

    $authenticator = new ApiKeyAuthenticator('X-API-Key', 'key-123');

    $authenticator->apply(Http::withOptions([]))->get('https://example.test/ping');

    Http::assertSent(fn (Request $request): bool => $request->header('X-API-Key')[0] === 'key-123');
});

it('does not duplicate the header when re-applied on retry', function () {
    Http::fake();

    $authenticator = new ApiKeyAuthenticator('X-API-Key', 'key-123');

    $request = $authenticator->apply(Http::withOptions([]));
    $authenticator->apply($request)->get('https://example.test/ping');

    Http::assertSent(fn (Request $request): bool => $request->header('X-API-Key') === ['key-123']);
});
