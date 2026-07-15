<?php

declare(strict_types=1);

namespace Uzbek\Sms\Drivers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use Uzbek\Sms\Authenticators\BasicAuthenticator;
use Uzbek\Sms\Concerns\VerifiesWebhookSecurity;
use Uzbek\Sms\Contracts\Authenticator;
use Uzbek\Sms\Contracts\HandlesWebhooks;
use Uzbek\Sms\Data\DeliveryReport;
use Uzbek\Sms\Data\OutboundMessage;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Enums\DeliveryStatus;

final class PlayMobileDriver extends AbstractDriver implements HandlesWebhooks
{
    use VerifiesWebhookSecurity;

    public static function resolveAuthenticator(
        array $config,
        CacheRepository $cache,
        HttpFactory $http,
    ): Authenticator {
        return new BasicAuthenticator((string) $config['username'], (string) $config['password']);
    }

    protected function doSend(string $phone, string $text): SentMessage
    {
        return $this->sendBatch(Collection::make([new OutboundMessage($phone, $text)]))->first();
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

    public function verifyWebhook(Request $request): void
    {
        $this->verifyWebhookSecurity($request);
    }

    public function parseWebhook(Request $request): iterable
    {
        foreach ((array) $request->input('messages', []) as $entry) {
            yield new DeliveryReport(
                providerMessageId: (string) ($entry['message-id'] ?? ''),
                status: $this->mapStatus((string) ($entry['status'] ?? '')),
                raw: (array) $entry,
            );
        }
    }

    /**
     * The messages array IS the native bulk mechanism.
     *
     * @param  Collection<int, OutboundMessage>  $messages
     * @return Collection<int, SentMessage>
     */
    private function sendBatch(Collection $messages): Collection
    {
        $entries = $messages->map(fn (OutboundMessage $message): array => [
            'recipient' => $this->normalizePhone($message->phone),
            'message-id' => (string) Str::ulid(),
            'sms' => [
                'originator' => (string) ($this->config['from'] ?? ''),
                'content' => ['text' => $message->text],
            ],
        ]);

        $response = $this->http()->post('send', ['messages' => $entries->all()]);

        // TODO: verify whether codes arrive as HTTP status or body field
        $errorCode = $response->json('error-code');

        if ($errorCode !== null) {
            $error = sprintf('PlayMobile error %s: %s', $errorCode, $response->json('error-description') ?? 'unknown');

            return $entries->map(fn (array $entry): SentMessage => SentMessage::failed(
                provider: $this->name(),
                phone: $entry['recipient'],
                text: $entry['sms']['content']['text'],
                errorMessage: $error,
                raw: (array) $response->json(),
            ));
        }

        $raw = (array) $response->json();

        return $entries->map(fn (array $entry): SentMessage => SentMessage::success(
            provider: $this->name(),
            phone: $entry['recipient'],
            text: $entry['sms']['content']['text'],
            providerMessageId: $entry['message-id'],
            raw: $raw,
        ));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function mapStatus(string $status): DeliveryStatus
    {
        return match (strtolower($status)) {
            'transmitted' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'notdelivered', 'expired' => DeliveryStatus::Undelivered,
            'rejected', 'failed' => DeliveryStatus::Failed,
            default => DeliveryStatus::Unknown,
        };
    }
}
