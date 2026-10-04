<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Override;
use Potato\SmartJudge\Domain\Context;
use Potato\SmartJudge\Domain\Driver;
use Potato\SmartJudge\Domain\Question;

/**
 * Keeps a driver's answers per scope, so the same request is not paid for twice.
 */
final readonly class AnswerCache implements Driver
{
    /**
     * @param int $ttl seconds an answer is kept
     */
    public function __construct(
        private Driver $driver,
        private Repository $cache,
        private ScopeVersions $versions,
        private string $scope,
        private int $ttl,
    ) {
    }

    #[Override]
    public function name(): string
    {
        return $this->driver->name();
    }

    #[Override]
    public function answer(array $facts, array $questions, ?Context $context): array
    {
        $key = $this->key($facts, $questions, $context);
        $cached = $this->cache->get($key);

        if (\is_array($cached)) {
            /** @var array<string, float> $cached */
            return $cached;
        }

        $answer = $this->driver->answer($facts, $questions, $context);
        $this->cache->put($key, $answer, $this->ttl);

        return $answer;
    }

    /**
     * The driver's name is in the hash, so a model change never serves the answers of the model before.
     *
     * @param array<string, array<string, mixed>> $facts
     * @param array<string, Question> $questions
     */
    private function key(array $facts, array $questions, ?Context $context): string
    {
        $request = json_encode(
            [$this->driver->name(), $facts, $questions, $context],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        return \sprintf('smart-judge:%s:v%s:%s', $this->scope, $this->versions->of($this->scope), hash('sha256', $request));
    }
}
