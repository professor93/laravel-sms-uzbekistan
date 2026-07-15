<?php

declare(strict_types=1);

namespace Uzbek\Sms\Concerns;

use Illuminate\Http\Request;
use Uzbek\Sms\Exceptions\SmsException;

/**
 * Shared webhook guards for drivers: a ?token= shared secret and an IP
 * allowlist, each enforced only when configured on the provider.
 */
trait VerifiesWebhookSecurity
{
    /**
     * @throws SmsException
     */
    protected function verifyWebhookSecurity(Request $request): void
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($secret !== '' && ! hash_equals($secret, (string) $request->query('token', ''))) {
            throw new SmsException(sprintf('[%s] webhook token mismatch.', $this->name()));
        }

        $allowedIps = (array) ($this->config['allowed_ips'] ?? []);

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            throw new SmsException(sprintf('[%s] webhook from unexpected IP.', $this->name()));
        }
    }
}
