<?php

declare(strict_types=1);

namespace Uzbek\Sms\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Uzbek\Sms\Exceptions\SmsException;

/**
 * Shared webhook guards for drivers: a ?token= shared secret and an IP
 * allowlist (exact IPs and CIDR ranges, IPv4 + IPv6), each enforced only
 * when configured on the provider.
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

        $allowedIps = array_values(array_filter((array) ($this->config['allowed_ips'] ?? []), 'is_string'));

        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            throw new SmsException(sprintf('[%s] webhook from unexpected IP.', $this->name()));
        }
    }
}
