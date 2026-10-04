<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Wires SmartJudge into the app; every boundary is a binding the app can replace.
 */
final class SmartJudgeServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__ . '/../config/smart-judge.php';

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'smart-judge');

        $this->app->singleton(JudgeFactory::class);

        // the Guzzle client the TypeSafe driver sends its requests with
        $this->app->bind('smart-judge.http', static fn (): Client => new Client());

        // the driver config selects, or null when it is not configured
        $this->app->bind(
            'smart-judge.driver',
            static fn (Container $app): mixed => $app->make(JudgeFactory::class)->driver(),
        );

        // null in config means the app's default store and channel
        $this->app->bind(
            'smart-judge.cache',
            static fn (Container $app): mixed => $app->make('cache')->store(self::nameIn($app, 'smart-judge.cache.store')),
        );

        $this->app->bind(
            'smart-judge.logger',
            static fn (Container $app): mixed => $app->make('log')->channel(self::nameIn($app, 'smart-judge.log.channel')),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG => $this->app->configPath('smart-judge.php')], 'smart-judge-config');
        }
    }

    private static function nameIn(Container $app, string $key): ?string
    {
        $name = $app->make('config')->get($key);

        return \is_string($name) ? $name : null;
    }
}
