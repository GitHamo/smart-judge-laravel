<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Override;
use Potato\SmartJudge\Domain\Context;
use Potato\SmartJudge\Domain\Driver;
use Potato\SmartJudge\Domain\JudgeUnavailable;

/**
 * Stops asking a driver for a while after it was unavailable, in every scope, so an unavailable driver costs one slow request.
 */
final readonly class PauseAfterUnavailable implements Driver
{
    /**
     * @param int $seconds how long the driver is not asked after it was unavailable
     */
    public function __construct(
        private Driver $driver,
        private Repository $cache,
        private int $seconds,
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
        // one key per driver, not per scope, so one detected outage pauses every scope
        $key = 'smart-judge:unavailable:' . $this->driver->name();

        if ($this->cache->has($key)) {
            throw new JudgeUnavailable($this->driver->name(), null, \sprintf('Not asked, as it was unavailable within the last %d seconds.', $this->seconds));
        }

        try {
            return $this->driver->answer($facts, $questions, $context);
        } catch (JudgeUnavailable $e) {
            $this->cache->put($key, true, $this->seconds);

            throw $e;
        }
    }
}
