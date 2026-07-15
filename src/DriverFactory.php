<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Drivers\AbstractDriver;
use Uzbek\Sms\Drivers\EskizDriver;
use Uzbek\Sms\Drivers\PlayMobileDriver;
use Uzbek\Sms\Drivers\SayqalDriver;
use Uzbek\Sms\Drivers\TextUpDriver;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Exceptions\UnknownDriverException;
use Uzbek\Sms\Exceptions\UnknownProviderException;

class DriverFactory
{
    /**
     * @var array<string, class-string<Drivers\AbstractDriver>>
     */
    private const DRIVERS = [
        'eskiz' => EskizDriver::class,
        'playmobile' => PlayMobileDriver::class,
        'textup' => TextUpDriver::class,
        'sayqal' => SayqalDriver::class,
    ];

    /** Login credentials that make one account's token distinct from another's. */
    private const IDENTITY_KEYS = ['email', 'password', 'username', 'secret_key'];

    /**
     * @var array<string, Driver>
     */
    private array $resolved = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $config  runtime overrides (e.g. custom email/password)
     *
     * @throws UnknownProviderException
     * @throws UnknownDriverException
     * @throws DriverDisabledException
     */
    public function make(string $provider, array $config = []): Driver
    {
        // Only the config path is memoized; overrides may hold unserializable values.
        if ($config === []) {
            return $this->resolved[$provider] ??= $this->build($provider, []);
        }

        return $this->build($provider, $config);
    }

    public function default(): Driver
    {
        return $this->make((string) $this->config->get('sms.default'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function build(string $provider, array $overrides): Driver
    {
        $base = $this->config->get("sms.providers.{$provider}");

        if (! is_array($base)) {
            throw UnknownProviderException::make($provider);
        }

        $config = array_replace($base, $overrides);

        if (! ($config['enabled'] ?? true)) {
            throw DriverDisabledException::make($provider);
        }

        $class = $this->driverClass((string) ($config['driver'] ?? $provider));

        // Overriding credentials without an explicit user_id must not reuse the base account's.
        if (! array_key_exists('user_id', $overrides) && $this->identity($config) !== $this->identity($base)) {
            unset($config['user_id']);
        }

        $config['name'] = $provider;
        $config['cache_key'] = $this->cacheKey($provider, $config, $base);

        $cache = $this->cache->store($this->config->get('sms.cache.store'));

        return new $class($class::resolveAuthenticator($config, $cache, $this->http), $this->http, $config, $cache);
    }

    /**
     * Resolves an alias through the built-in map extended by the sms.drivers
     * config map (same alias overrides the built-in), or accepts a direct FQCN.
     *
     * @return class-string<Drivers\AbstractDriver>
     */
    private function driverClass(string $driver): string
    {
        $custom = array_filter((array) $this->config->get('sms.drivers', []), 'is_string');

        $class = array_replace(self::DRIVERS, $custom)[$driver] ?? $driver;

        if (is_subclass_of($class, AbstractDriver::class)) {
            return $class;
        }

        throw UnknownDriverException::make($driver);
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $base
     */
    private function cacheKey(string $provider, array $merged, array $base): string
    {
        $key = sprintf('%s:%s:token', $this->config->get('sms.cache.prefix', 'sms'), $provider);

        $identity = $this->identity($merged);

        // Only different credentials get their own cached token — a hash keeps them apart.
        if ($identity === $this->identity($base)) {
            return $key;
        }

        return $key.':'.substr(md5((string) json_encode($identity)), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function identity(array $config): array
    {
        $identity = [];

        foreach (self::IDENTITY_KEYS as $field) {
            if (isset($config[$field])) {
                $identity[$field] = $config[$field];
            }
        }

        return $identity;
    }
}
