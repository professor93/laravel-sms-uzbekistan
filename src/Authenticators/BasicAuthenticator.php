<?php

declare(strict_types=1);

namespace Uzbek\Sms\Authenticators;

use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;
use Uzbek\Sms\Contracts\Authenticator;

final class BasicAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->withBasicAuth($this->username, $this->password);
    }

    public function refresh(): void {}
}
