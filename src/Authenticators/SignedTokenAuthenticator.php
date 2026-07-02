<?php

declare(strict_types=1);

namespace Uzbek\Sms\Authenticators;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Uzbek\Sms\Contracts\Authenticator;

final class SignedTokenAuthenticator implements Authenticator
{
    /**
     * @param  Closure(RequestInterface): string  $signer
     */
    public function __construct(
        private readonly string $header,
        private readonly Closure $signer,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        // Middleware sees the final PSR-7 request: path and body are known.
        return $request->withRequestMiddleware(
            fn (RequestInterface $psrRequest): RequestInterface => $psrRequest->withHeader(
                $this->header,
                ($this->signer)($psrRequest),
            ),
        );
    }

    public function refresh(): void
    {
        // Signature is computed fresh per request; it can never go stale.
    }
}
