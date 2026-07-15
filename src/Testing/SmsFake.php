<?php

declare(strict_types=1);

namespace Uzbek\Sms\Testing;

use Closure;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\PendingBulkMessage;
use Uzbek\Sms\PendingMessage;

/**
 * Swapped in by Sms::fake(): every provider resolves to a RecordingDriver, so
 * application code runs unchanged while sends are only recorded. Also acts as
 * the default Driver so facade assertions work directly.
 */
final class SmsFake extends DriverFactory implements Driver
{
    /** @var list<SentMessage> */
    private array $recorded = [];

    /** @var array<string, RecordingDriver> */
    private array $fakes = [];

    public function make(string $provider, array $config = []): Driver
    {
        return $this->fakes[$provider] ??= new RecordingDriver($provider, $this);
    }

    public function record(SentMessage $message): void
    {
        $this->recorded[] = $message;
    }

    /**
     * @return Collection<int, SentMessage>
     */
    public function sent(): Collection
    {
        return Collection::make($this->recorded);
    }

    /**
     * @param  Closure(SentMessage): bool|null  $callback
     */
    public function assertSent(?Closure $callback = null): void
    {
        Assert::assertTrue(
            $this->sent()->contains(fn (SentMessage $message): bool => $callback === null || $callback($message)),
            'No matching SMS was sent.',
        );
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded, sprintf(
            'Expected %d sent SMS, got %d.',
            $count,
            count($this->recorded),
        ));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->recorded, sprintf(
            'Expected no sent SMS, got %d.',
            count($this->recorded),
        ));
    }

    public function assertSentTo(string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        Assert::assertTrue(
            $this->sent()->contains(fn (SentMessage $message): bool => preg_replace('/\D+/', '', $message->phone) === $digits),
            sprintf('No SMS was sent to [%s].', $phone),
        );
    }

    public function send(string $phone, string $text): SentMessage
    {
        return $this->defaultDriver()->send($phone, $text);
    }

    public function sendMany(iterable $messages, ?string $fallback = null, ?Closure $fallbackWhen = null): Collection
    {
        return $this->defaultDriver()->sendMany($messages, $fallback, $fallbackWhen);
    }

    public function to(string $phone): PendingMessage
    {
        return $this->defaultDriver()->to($phone);
    }

    public function many(iterable $messages): PendingBulkMessage
    {
        return $this->defaultDriver()->many($messages);
    }

    public function name(): string
    {
        return $this->defaultDriver()->name();
    }

    public function defaultFallback(): ?string
    {
        return $this->defaultDriver()->defaultFallback();
    }

    private function defaultDriver(): Driver
    {
        return $this->make((string) config('sms.default'));
    }
}
