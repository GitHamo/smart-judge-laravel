<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Potato\SmartJudge\Application\Judge;
use Potato\SmartJudge\Domain\Driver;

/**
 * Gives the app a judge per scope, or none when SmartJudge is off or its driver is not configured, so the app falls back.
 */
final class JudgeFactory
{
    /** @var array<string, Closure(array<string, mixed>): ?Driver> */
    private array $drivers = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {
    }

    /**
     * @param string $scope the feature that asks, e.g. `recurring`
     *
     * @throws InvalidArgumentException when the selected driver is not registered or its config is invalid
     */
    public function make(string $scope): ?Judge
    {
        if (true !== (bool) $this->config->get('smart-judge.enabled')) {
            return null;
        }

        // through the container, so the app can replace the driver by binding `smart-judge.driver`
        /** @var Driver|null $driver */
        $driver = $this->container->make('smart-judge.driver');

        if (null === $driver) {
            return null;
        }

        /** @var CacheRepository $cache */
        $cache = $this->container->make('smart-judge.cache');
        $paused = new PauseAfterUnavailable($driver, $cache, $this->seconds('smart-judge.cache.unavailable_ttl'));

        return new Judge(new AnswerCache($paused, $cache, new ScopeVersions($cache), $scope, $this->ttl($scope)));
    }

    /**
     * Registers a driver that config selects by its name; its callback gets the driver's config array, `drivers.<name>`,
     * and returns null when that config is not enough to ask, e.g. without a key.
     *
     * @param Closure(array<string, mixed>): ?Driver $callback
     */
    public function extend(string $name, Closure $callback): void
    {
        $this->drivers[$name] = $callback;
    }

    /**
     * The driver config selects, or null when it is not configured; what `smart-judge.driver` resolves to unless the app replaces it.
     *
     * @throws InvalidArgumentException when no driver is registered by the selected name, or its config is invalid
     */
    public function driver(): ?Driver
    {
        $name = $this->config->get('smart-judge.driver');

        if (!\is_string($name) || !isset($this->drivers[$name])) {
            throw new InvalidArgumentException(\sprintf('SmartJudge driver "%s" is not registered.', \is_string($name) ? $name : get_debug_type($name)));
        }

        /** @var array<string, mixed> $config */
        $config = (array) $this->config->get('smart-judge.drivers.' . $name, []);

        return ($this->drivers[$name])($config);
    }

    /**
     * Seconds an answer is kept: the scope's own TTL, else the global one.
     */
    private function ttl(string $scope): int
    {
        $key = 'smart-judge.scopes.' . $scope . '.ttl';

        return null !== $this->config->get($key) ? $this->seconds($key) : $this->seconds('smart-judge.cache.ttl');
    }

    /**
     * @throws InvalidArgumentException when the config value is not a number of seconds
     */
    private function seconds(string $key): int
    {
        $seconds = $this->config->get($key);

        if (!is_numeric($seconds)) {
            throw new InvalidArgumentException(\sprintf('SmartJudge config "%s" needs a number of seconds.', $key));
        }

        return (int) $seconds;
    }
}
