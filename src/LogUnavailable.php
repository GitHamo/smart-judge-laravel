<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel;

use Override;
use Potato\SmartJudge\Domain\Context;
use Potato\SmartJudge\Domain\Driver;
use Potato\SmartJudge\Domain\JudgeUnavailable;
use Psr\Log\LoggerInterface;

/**
 * Warns each time a request to the driver finds it unavailable, so an operator sees why; answers are not logged.
 */
final readonly class LogUnavailable implements Driver
{
    /**
     * @param string $scope the feature that asked, logged so the operator knows who was affected
     */
    public function __construct(
        private Driver $driver,
        private LoggerInterface $logger,
        private string $scope,
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
        try {
            return $this->driver->answer($facts, $questions, $context);
        } catch (JudgeUnavailable $e) {
            $this->logger->warning('SmartJudge driver is unavailable.', [
                'driver' => $e->driver,
                'status' => $e->status,
                'reason' => $e->reason,
                'scope' => $this->scope,
            ]);

            throw $e;
        }
    }
}
