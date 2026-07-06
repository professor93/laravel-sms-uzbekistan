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

final class TextUpDriver extends AbstractDriver implements ChecksDeliveryStatus
{
    private const DEFAULT_TOKEN_TTL = 86400;

    public function name(): string
    {
        return 'textup';
    }

    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        $ttl = (int) ($config['token_ttl'] ?? self::DEFAULT_TOKEN_TTL);

        // Login lives on a separate host, hence the absolute URL.
        return new LoginTokenAuthenticator(
            cache: $cache,
            cacheKey: (string) $config['cache_key'],
            login: function () use ($config, $cache, $http, $ttl): string {
                $response = $http
                    ->asJson()
                    ->acceptJson()
                    ->withOptions($config['http_options'] ?? [])
                    ->post(rtrim((string) $config['auth_url'], '/').'/login', [
                        'email' => $config['email'],
                        'password' => $config['password'],
                    ])
                    ->throw();

                // userId is required in send payloads; cache it beside the token.
                $userId = $response->json('user.id');

                if ($userId !== null) {
                    $cache->put($config['cache_key'].':user', (string) $userId, $ttl);
                }

                return (string) $response->json('accessToken');
            },
            ttl: $ttl,
        );
    }

    protected function doSend(string $phone, string $text): SentMessage
    {
        $phone = $this->normalizePhone($phone);

        $response = $this->http()->post('send', $this->payload($text, [$phone]));

        $smsId = $response->json('smsId');

        return SentMessage::success(
            driver: $this->name(),
            phone: $phone,
            text: $text,
            providerMessageId: $smsId !== null ? (string) $smsId : null,
            raw: (array) $response->json(),
        );
    }

    // Native bulk shares one text; mixed texts use the base loop.
    protected function doSendMany(Collection $messages): Collection
    {
        if ($messages->pluck('text')->unique()->count() > 1) {
            return parent::doSendMany($messages);
        }

        try {
            $results = $this->sendBatch($messages);
        } catch (Throwable $e) {
            $results = $this->failAll($messages, $e);
        }

        return $this->finalizeBulk($results);
    }

    public function checkStatus(string $providerMessageId): DeliveryStatus
    {
        // Bulk ids are "{smsId}:{phone}" — the provider only knows the smsId.
        $smsId = Str::before($providerMessageId, ':');

        $response = $this->http()->get("sms/{$smsId}")->throw();

        // TODO: verify status response shape and values against a live TextUp response
        return $this->mapStatus((string) $response->json('status'));
    }

    /**
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    private function sendBatch(Collection $messages): Collection
    {
        $phones = $messages->map(fn (OutboundMessage $message): string => $this->normalizePhone($message->phone));

        $response = $this->http()->post('send', $this->payload((string) $messages->first()->text, $phones->all()));

        $smsId = (string) $response->json('smsId');
        $raw = (array) $response->json();

        // One smsId covers every recipient; suffix the phone to keep ids unique.
        return $messages->map(fn (OutboundMessage $message, int $index): SentMessage => SentMessage::success(
            driver: $this->name(),
            phone: $phones[$index],
            text: $message->text,
            providerMessageId: "{$smsId}:{$phones[$index]}",
            raw: $raw,
        ));
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<string, mixed>
     */
    private function payload(string $text, array $recipients): array
    {
        return array_filter([
            'message' => $text,
            'userId' => $this->userId(),
            'recipients' => $recipients,
            'nicknameId' => $this->config['from'] ?? null,
            'isOtp' => ($this->config['is_otp'] ?? false) ? true : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function userId(): ?string
    {
        $configured = (string) ($this->config['user_id'] ?? '');

        if ($configured !== '') {
            return $configured;
        }

        // Otherwise use the id captured at login; null omits it rather than sending "".
        $captured = $this->cache->get($this->config['cache_key'].':user');

        return is_string($captured) && $captured !== '' ? $captured : null;
    }

    private function normalizePhone(string $phone): string
    {
        return '+'.preg_replace('/\D+/', '', $phone);
    }

    private function mapStatus(string $status): DeliveryStatus
    {
        return match (strtolower($status)) {
            'pending', 'waiting', 'new' => DeliveryStatus::Pending,
            'sent', 'transmitted' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'undelivered', 'expired' => DeliveryStatus::Undelivered,
            'failed', 'rejected' => DeliveryStatus::Failed,
            default => DeliveryStatus::Unknown,
        };
    }
}
