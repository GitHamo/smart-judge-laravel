<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Illuminate\Contracts\Cache\Repository;

/**
 * The version of each scope's cached answers; clearing a scope moves it to a new version, so its old answers are never read again
 * and expire on their own, on every cache store.
 */
final readonly class ScopeVersions
{
    // counts the clears of every scope at once, since the scopes in use are not known
    private const string ALL = 'smart-judge:version';

    public function __construct(
        private Repository $cache,
    ) {
    }

    /**
     * @return string e.g. `0.2`: the clears of every scope, then the clears of this scope
     */
    public function of(string $scope): string
    {
        return $this->count(self::ALL) . '.' . $this->count(self::key($scope));
    }

    /**
     * @param string|null $scope null clears every scope
     */
    public function clear(?string $scope): void
    {
        $key = null === $scope ? self::ALL : self::key($scope);

        // not increment(): some stores cannot increment a key that is not there yet
        $this->cache->forever($key, $this->count($key) + 1);
    }

    private function count(string $key): int
    {
        $count = $this->cache->get($key);

        // numeric, not int: stores such as Redis keep numbers unserialized and give them back as strings
        return is_numeric($count) ? (int) $count : 0;
    }

    private static function key(string $scope): string
    {
        return 'smart-judge:' . $scope . ':version';
    }
}
