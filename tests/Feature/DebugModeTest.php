<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Uzbek\Sms\Data\OutboundMessage;

function fakeTextUpSuccess(): void
{
    Http::fake([
        'api-auth.textup.uz/v1/login' => Http::response(['accessToken' => 'jwt-secret', 'user' => ['id' => 'u1']]),
        'sms-api.textup.uz/v1/send' => Http::response(['smsId' => 'sms-1']),
    ]);
}

it('leaves debug null when not enabled', function () {
    fakeTextUpSuccess();

    $result = sms('textup')->to('+998901234567')->text('Salom')->send();

    expect($result->debug)->toBeNull();
});

it('leaves debug null when explicitly disabled', function () {
    fakeTextUpSuccess();

    $result = sms('textup')->to('+998901234567')->text('Salom')->debug(false)->send();

    expect($result->debug)->toBeNull();
});

it('collects login and send exchanges with credentials redacted', function () {
    fakeTextUpSuccess();

    $result = sms('textup')->to('+998901234567')->text('Salom')->debug()->send();

    expect($result->debug)->toBeArray();

    $requests = collect($result->debug)->where('type', 'request')->values();

    $login = $requests->first(fn (array $entry): bool => str_contains($entry['url'], 'api-auth.textup.uz'));
    $send = $requests->first(fn (array $entry): bool => str_contains($entry['url'], 'sms-api.textup.uz'));

    expect($login)->not->toBeNull()
        ->and($login['request']['password'])->toBe('••••••')
        ->and($login['response']['accessToken'])->toBe('••••••')
        ->and($send)->not->toBeNull()
        ->and($send['method'])->toBe('POST')
        ->and($send['status'])->toBe(200)
        ->and($send['response']['smsId'])->toBe('sms-1');
});

it('records the fallback decision between the primary and fallback exchanges', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
        'send.smsxabar.uz/broker-api/send' => Http::response(),
    ]);

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->useFallback('playmobile')->debug()->send();

    expect($result->successful)->toBeTrue()
        ->and($result->fallbackFrom)->toBe('eskiz')
        ->and($result->debug)->toBeArray();

    $entries = collect($result->debug);

    $failedSend = $entries->search(fn (array $e): bool => $e['type'] === 'request' && str_contains($e['url'], 'message/sms/send'));
    $fallback = $entries->search(fn (array $e): bool => $e['type'] === 'fallback');
    $fallbackSend = $entries->search(fn (array $e): bool => $e['type'] === 'request' && str_contains($e['url'], 'smsxabar.uz'));

    expect($entries->get($failedSend)['status'])->toBe(500)
        ->and($entries->get($fallback))->toMatchArray(['from' => 'eskiz', 'to' => 'playmobile'])
        ->and($failedSend)->toBeLessThan($fallback)
        ->and($fallback)->toBeLessThan($fallbackSend)
        ->and($entries->where('type', 'exception'))->toBeEmpty();
});

it('appends an exception entry when the final result fails', function () {
    Http::fake([
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['message' => 'boom'], 500),
    ]);

    $result = sms('eskiz')->to('+998901234567')->text('Salom')->withoutFallback()->debug()->send();

    $exception = collect($result->debug)->firstWhere('type', 'exception');

    expect($result->successful)->toBeFalse()
        ->and($exception)->not->toBeNull()
        ->and($exception['provider'])->toBe('eskiz')
        ->and($exception['message'])->toBe($result->errorMessage);
});

it('attaches the same trace to every bulk result', function () {
    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
    ]);

    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom');

    $results = sms('sayqal')->many($messages)->debug()->send();

    expect($results->get(0)->debug)->toBeArray()
        ->and($results->get(0)->debug)->toBe($results->get(1)->debug)
        ->and(collect($results->get(0)->debug)->where('type', 'request'))->toHaveCount(2);
});

it('records bulk fallback decisions in the trace', function () {
    config()->set('sms.providers.sayqal.prefixes', ['blocked' => ['99899']]);

    Http::fake([
        'routee.sayqal.uz/sms/TransmitSMS' => Http::response(['transactionid' => 7]),
        'notify.eskiz.uz/api/auth/login' => Http::response(['data' => ['token' => 'jwt-1']]),
        'notify.eskiz.uz/api/message/sms/send' => Http::response(['id' => 5, 'status' => 'waiting']),
    ]);

    $messages = OutboundMessage::sameText(['+998901111111', '+998998990000'], 'Salom');

    $results = sms('sayqal')->many($messages)->useFallback('eskiz')->debug()->send();

    $fallback = collect($results->get(0)->debug)->firstWhere('type', 'fallback');

    expect($fallback)->not->toBeNull()
        ->and($fallback['from'])->toBe('sayqal')
        ->and($fallback['to'])->toBe('eskiz');
});
