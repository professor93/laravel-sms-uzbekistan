<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\PendingMessage;

it('sends through the fluent builder', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 42, 'status' => 'waiting']),
    ]);

    $message = app(DriverFactory::class)
        ->make('eskiz')
        ->to('+998901234567')
        ->text('Salom')
        ->send();

    expect($message->successful)->toBeTrue()
        ->and($message->providerMessageId)->toBe('42');
});

it('refuses to send without a text', function () {
    app(DriverFactory::class)->make('eskiz')->to('+998901234567')->send();
})->throws(LogicException::class, 'No text set');

it('refuses to send without a recipient', function () {
    $pending = new PendingMessage(app(DriverFactory::class)->make('eskiz'));

    $pending->text('Salom')->send();
})->throws(LogicException::class, 'No recipient set');
