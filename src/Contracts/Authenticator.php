<?php

declare(strict_types=1);

namespace Uzbek\Sms\Contracts;

use Illuminate\Http\Client\PendingRequest;

interface Authenticator
{
    public function apply(PendingRequest $request): PendingRequest;

    public function refresh(): void;
}
