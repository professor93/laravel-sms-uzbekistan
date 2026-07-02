<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use Uzbek\Sms\Authenticators\LoginTokenAuthenticator;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\ChecksDeliveryStatus;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Enums\DeliveryStatus;

final class EskizDriver extends AbstractDriver implements ChecksDeliveryStatus
{
    private const DEFAULT_TOKEN_TTL = 2592000;

    public function name(): string
    {
        return 'eskiz';
    }

    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        return new LoginTokenAuthenticator(
            cache: $cache,
            cacheKey: (string) $config['cache_key'],
            login: fn (): string => (string) $http
                ->asJson()
                ->acceptJson()
                ->withOptions($config['http_options'] ?? [])
                ->post(rtrim((string) $config['base_url'], '/').'/auth/login', [
                    'email' => $config['email'],
                    'password' => $config['password'],
                ])
                ->throw()
                ->json('data.token'),
            ttl: (int) ($config['token_ttl'] ?? self::DEFAULT_TOKEN_TTL),
        );
    }

    protected function doSend(string $phone, string $text): SentMessage
    {
        $phone = $this->normalizePhone($phone);

        $response = $this->http()->post('message/sms/send', array_filter([
            'mobile_phone' => $phone,
            'message' => $text,
            'from' => $this->config['from'] ?? null,
            'callback_url' => $this->config['callback_url'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        $id = $response->json('id');

        return SentMessage::success(
            driver: $this->name(),
            phone: $phone,
            text: $text,
            providerMessageId: $id !== null ? (string) $id : null,
            raw: (array) $response->json(),
            status: $this->mapStatus((string) $response->json('status')),
        );
    }

    protected function doSendMany(Collection $messages): Collection
    {
        try {
            $results = $this->sendBatch($messages);
        } catch (Throwable $e) {
            $results = $this->failAll($messages, $e);
        }

        return $this->finalizeBulk($results);
    }

    public function checkStatus(string $providerMessageId): DeliveryStatus
    {
        $response = $this->http()
            ->get("message/sms/status_by_id/{$providerMessageId}")
            ->throw();

        return $this->mapStatus((string) $response->json('data.status'));
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    private function sendBatch(Collection $messages): Collection
    {
        $entries = $messages->map(fn (OutboundMessage $message): array => [
            'user_sms_id' => (string) Str::ulid(),
            'to' => $this->normalizePhone($message->phone),
            'text' => $message->text,
        ]);

        $response = $this->http()->post('message/sms/send-batch', array_filter([
            'messages' => $entries->all(),
            'from' => $this->config['from'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        // TODO: Eskiz batch javobini real API bilan tekshirish
        $raw = (array) $response->json();

        return $entries->map(fn (array $entry): SentMessage => SentMessage::success(
            driver: $this->name(),
            phone: $entry['to'],
            text: $entry['text'],
            providerMessageId: $entry['user_sms_id'],
            raw: $raw,
        ));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    // Eskiz mixes SMPP DLR codes with full words (status_by_id returns DELIVERED).
    private function mapStatus(string $status): DeliveryStatus
    {
        return match (strtoupper($status)) {
            'NEW', 'STORED', 'WAITING' => DeliveryStatus::Pending,
            'TRANSMTD', 'TRANSMITTED' => DeliveryStatus::Sent,
            'DELIVRD', 'DELIVERED' => DeliveryStatus::Delivered,
            'UNDELIV', 'UNDELIVERED', 'EXPIRED' => DeliveryStatus::Undelivered,
            'REJECTD', 'REJECTED', 'DELETED' => DeliveryStatus::Failed,
            default => DeliveryStatus::Unknown,
        };
    }
}
