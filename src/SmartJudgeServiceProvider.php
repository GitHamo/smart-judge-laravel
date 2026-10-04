<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Override;
use Potato\SmartJudge\Infrastructure\Drivers\TypeSafe;

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

        $this->app->singleton(JudgeFactory::class, static function (Container $app): JudgeFactory {
            $factory = new JudgeFactory($app, $app->make('config'));
            $factory->extend('typesafe', static fn (array $config): ?TypeSafe => self::typeSafe($app, $config));

            return $factory;
        });

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

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a key is given without a model, a base_url or a timeout
     */
    private static function typeSafe(Container $app, array $config): ?TypeSafe
    {
        $key = $config['key'] ?? null;

        // without a key there is no judge, so the app falls back
        if (!\is_string($key) || '' === $key) {
            return null;
        }

        $model = $config['model'] ?? null;
        $baseUrl = $config['base_url'] ?? null;
        $timeout = $config['timeout'] ?? null;

        if (!\is_string($model) || !\is_string($baseUrl) || !is_numeric($timeout)) {
            throw new InvalidArgumentException('SmartJudge driver "typesafe" needs a model, a base_url and a timeout.');
        }

        return new TypeSafe($key, $model, $baseUrl, $app->make('smart-judge.http'), (float) $timeout);
    }

    private static function nameIn(Container $app, string $key): ?string
    {
        $name = $app->make('config')->get($key);

        return \is_string($name) ? $name : null;
    }
}
