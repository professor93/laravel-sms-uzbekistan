<?php

declare(strict_types=1);

namespace Uzbek\Sms\Otp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Throwable;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\DriverFactory;
use Uzbek\Sms\Enums\OtpStatus;

/**
 * Cache-backed one-time codes: hashed at rest, single-use, attempt-limited,
 * resend-throttled. All knobs live under the sms.otp config block.
 */
final class OtpBroker
{
    public function __construct(private readonly DriverFactory $factory) {}

    /**
     * The template may be a name from sms.templates.list, a translation key
     * (localized via $locale or the app locale), or a raw string; :code and
     * any $params placeholders are filled in.
     *
     * @param  array<string, string|int|float>  $params
     */
    public function send(
        string $phone,
        ?string $provider = null,
        ?string $template = null,
        array $params = [],
        ?string $locale = null,
    ): SentMessage {
        $digits = $this->digits($phone);

        try {
            $cache = $this->cache();

            if ($cache->get($this->key($digits).':cooldown')) {
                return SentMessage::failed(
                    $provider ?? (string) config('sms.default'),
                    $phone,
                    '',
                    'OTP resend cooldown active; try again later.',
                );
            }

            $code = $this->generate();

            $cache->put($this->key($digits), ['hash' => $this->hash($digits, $code), 'attempts' => 0], $this->ttl());
            $cache->put($this->key($digits).':cooldown', true, max(1, (int) config('sms.otp.resend_cooldown', 60)));
        } catch (Throwable $e) {
            // Without storage the code could never be verified — do not send.
            $this->warn('OTP storage unavailable: '.$e->getMessage());

            return SentMessage::failed($provider ?? (string) config('sms.default'), $phone, '', 'OTP storage unavailable; code not sent.');
        }

        $driver = $provider === null ? $this->factory->default() : $this->factory->make($provider);

        $text = $this->renderTemplate($template, ['code' => $code] + $params, $locale);

        return $driver->to($phone)->text($text)->otp()->send();
    }

    /**
     * @param  array<string, string|int|float>  $params
     */
    private function renderTemplate(?string $template, array $params, ?string $locale): string
    {
        $key = $template ?? (string) config('sms.otp.template', 'Tasdiqlash kodi: :code');

        $registered = config("sms.templates.list.{$key}");

        $text = match (true) {
            is_string($registered) => $registered,
            Lang::has($key, $locale) => (string) Lang::get($key, [], $locale),
            default => $key,
        };

        foreach ($params as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public function verify(string $phone, string $code): OtpStatus
    {
        $digits = $this->digits($phone);

        try {
            $cache = $this->cache();

            $record = $cache->get($this->key($digits));

            if (! is_array($record) || ! isset($record['hash'])) {
                return OtpStatus::Expired;
            }

            if (hash_equals((string) $record['hash'], $this->hash($digits, $code))) {
                $cache->forget($this->key($digits));

                return OtpStatus::Valid;
            }

            $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;

            if ($record['attempts'] >= max(1, (int) config('sms.otp.max_attempts', 5))) {
                $cache->forget($this->key($digits));

                return OtpStatus::TooManyAttempts;
            }

            $cache->put($this->key($digits), $record, $this->ttl());

            return OtpStatus::Invalid;
        } catch (Throwable $e) {
            $this->warn('OTP verification storage unavailable: '.$e->getMessage());

            return OtpStatus::Expired;
        }
    }

    private function generate(): string
    {
        $length = max(4, (int) config('sms.otp.length', 6));

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    private function hash(string $digits, string $code): string
    {
        return hash_hmac('sha256', $digits.'|'.$code, (string) config('app.key'));
    }

    private function ttl(): int
    {
        return max(30, (int) config('sms.otp.ttl', 300));
    }

    private function key(string $digits): string
    {
        return sprintf('%s:otp:%s', config('sms.cache.prefix', 'sms'), $digits);
    }

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function cache(): CacheRepository
    {
        return cache()->store(config('sms.cache.store'));
    }

    private function warn(string $message): void
    {
        if (! config('sms.silent')) {
            Log::channel(config('sms.logging.channel'))->warning($message);
        }
    }
}
