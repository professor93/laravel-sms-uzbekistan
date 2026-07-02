<?php

declare(strict_types=1);

namespace Uzbek\Sms\Authenticators;

use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;
use Uzbek\Sms\Contracts\Authenticator;

final class BearerTokenAuthenticator implements Authenticator
{
    public function __construct(
        #[SensitiveParameter] private readonly string $token,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->token);
    }

    public function refresh(): void {}
}
