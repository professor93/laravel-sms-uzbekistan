<?php

declare(strict_types=1);

namespace Uzbek\Sms\Tests\Support;

use Illuminate\Http\Request;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Contracts\WebhookHandler;

final class RecordingWebhookHandler implements WebhookHandler
{
    /** @var list<array{provider: string, reports: array<int, mixed>, payload: array<string, mixed>}> */
    public static array $calls = [];

    public function handle(Request $request, Driver $driver, array $reports): void
    {
        self::$calls[] = [
            'provider' => $driver->name(),
            'reports' => $reports,
            'payload' => $request->all(),
        ];
    }
}
