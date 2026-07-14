<?php

declare(strict_types=1);

namespace Uzbek\Sms;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Uzbek\Sms\Contracts\Driver;
use Uzbek\Sms\Debug\DebugCollector;
use Uzbek\Sms\Events\DeliveryStatusUpdated;
use Uzbek\Sms\Events\SmsSent;
use Uzbek\Sms\Listeners\LogDeliveryStatusUpdate;
use Uzbek\Sms\Listeners\LogSentMessage;
use Uzbek\Sms\Listeners\PersistSentMessage;
use Uzbek\Sms\Listeners\UpdateDeliveryStatus;

final class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms.php', 'sms');

        $this->app->singleton(DriverFactory::class, fn (Application $app): DriverFactory => new DriverFactory(
            $app->make(HttpFactory::class),
            $app->make(ConfigRepository::class),
            $app->make(CacheFactory::class),
        ));

        $this->app->singleton(Driver::class, fn (Application $app): Driver => $app->make(DriverFactory::class)->default());

        $this->app->singleton(DebugCollector::class, fn (Application $app): DebugCollector => new DebugCollector(
            $app->make(Dispatcher::class),
        ));

        foreach (array_keys((array) $this->app->make(ConfigRepository::class)->get('sms.providers', [])) as $name) {
            $this->app->singleton(
                "sms.provider.{$name}",
                fn (Application $app): Driver => $app->make(DriverFactory::class)->make($name),
            );
        }
    }

    public function boot(): void
    {
        if (config('sms.webhook.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sms.php' => config_path('sms.php'),
            ], 'sms-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'sms-migrations');
        }
    }

    private function registerListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        if (config('sms.logging.database')) {
            $events->listen(SmsSent::class, PersistSentMessage::class);
            $events->listen(DeliveryStatusUpdated::class, UpdateDeliveryStatus::class);
        }

        if (config('sms.logging.debug')) {
            $events->listen(SmsSent::class, LogSentMessage::class);
            $events->listen(DeliveryStatusUpdated::class, LogDeliveryStatusUpdate::class);
        }
    }
}
