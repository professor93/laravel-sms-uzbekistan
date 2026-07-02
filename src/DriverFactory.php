<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Drivers\EskizDriver;
use Uzbek\Sms\Drivers\PlayMobileDriver;
use Uzbek\Sms\Drivers\SayqalDriver;
use Uzbek\Sms\Drivers\TextUpDriver;
use Uzbek\Sms\Exceptions\DriverDisabledException;
use Uzbek\Sms\Exceptions\UnknownDriverException;

final class DriverFactory
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

    /**
     * @var array<string, Driver>
     */
    private array $resolved = [];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::DRIVERS);
    }

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @throws UnknownDriverException
     * @throws DriverDisabledException
     */
    public function make(string $name): Driver
    {
        return $this->resolved[$name] ??= $this->build($name);
    }

    public function default(): Driver
    {
        return $this->make((string) $this->config->get('sms.default'));
    }

    private function build(string $name): Driver
    {
        $class = self::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw UnknownDriverException::make($name);
        }

        $config = (array) $this->config->get("sms.drivers.{$name}", []);

        if (! ($config['enabled'] ?? true)) {
            throw DriverDisabledException::make($name);
        }

        $config['cache_key'] = sprintf(
            '%s:%s:token',
            $this->config->get('sms.cache.prefix', 'sms'),
            $name,
        );

        $cache = $this->cache->store($this->config->get('sms.cache.store'));

        return new $class($class::resolveAuthenticator($config, $cache, $this->http), $this->http, $config);
    }
}
