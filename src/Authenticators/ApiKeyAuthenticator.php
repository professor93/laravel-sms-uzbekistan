<?php

declare(strict_types=1);

namespace Uzbek\Sms\Authenticators;

use Illuminate\Http\Client\PendingRequest;
use SensitiveParameter;
use Uzbek\Sms\Contracts\Authenticator;

final class ApiKeyAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $header,
        #[SensitiveParameter] private readonly string $key,
    ) {}

    public function apply(PendingRequest $request): PendingRequest
    {
        // replaceHeaders: withHeaders() would duplicate the key on 401 re-apply.
        return $request->replaceHeaders([$this->header => $this->key]);
    }

    public function refresh(): void {}
}
