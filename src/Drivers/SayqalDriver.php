<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Uzbek\Sms\Authenticators\SignedTokenAuthenticator;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Enums\DeliveryStatus;

final class SayqalDriver extends AbstractDriver implements ChecksDeliveryStatus
{
    public function name(): string
    {
        return 'sayqal';
    }

    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        return new SignedTokenAuthenticator(
            header: 'X-Access-Token',
            signer: function (RequestInterface $request) use ($config): string {
                $action = basename($request->getUri()->getPath());
                $body = (array) json_decode((string) $request->getBody(), true);

                // Doc recipe: md5("{Action} {username} {secretKey} {utime}")
                return md5(sprintf(
                    '%s %s %s %s',
                    $action,
                    $config['username'],
                    $config['secret_key'],
                    $body['utime'] ?? '',
                ));
            },
        );
    }

    // No bulk endpoint — sendMany() uses the base loop.
    protected function doSend(string $phone, string $text): SentMessage
    {
        $phone = $this->normalizePhone($phone);
        $smsId = (string) Str::ulid();

        $response = $this->http()->post('TransmitSMS', [
            'utime' => now()->unix(),
            'username' => (string) $this->config['username'],
            'service' => array_filter([
                'service' => (int) $this->config['service_id'],
                'nickname' => $this->config['from'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            'message' => [
                'smsid' => $smsId,
                'phone' => $phone,
                'text' => $text,
            ],
        ]);

        // Status needs BOTH ids — keep them together, split in checkStatus().
        $providerMessageId = sprintf('%s:%s', $response->json('transactionid'), $smsId);

        return SentMessage::success(
            driver: $this->name(),
            phone: $phone,
            text: $text,
            providerMessageId: $providerMessageId,
            raw: (array) $response->json(),
        );
    }

    public function checkStatus(string $providerMessageId): DeliveryStatus
    {
        [$transactionId, $smsId] = explode(':', $providerMessageId, 2);

        $response = $this->http()->post('StatusSMS', [
            'utime' => now()->unix(),
            'username' => (string) $this->config['username'],
            'transactionid' => (int) $transactionId,
            'smsid' => $smsId,
        ])->throw();

        return $this->mapStatus((int) $response->json('status'));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function mapStatus(int $status): DeliveryStatus
    {
        return match ($status) {
            0 => DeliveryStatus::Delivered,
            1, 2 => DeliveryStatus::Undelivered,
            3, 4 => DeliveryStatus::Sent,
            default => DeliveryStatus::Unknown,
        };
    }
}
