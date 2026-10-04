<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Log\LogManager;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use Potato\SmartJudge\Domain\JudgeUnavailable;
use Potato\SmartJudge\Domain\Subject;
use Potato\SmartJudge\Laravel\JudgeFactory;
use Psr\Log\LoggerInterface;

final class LogTest extends PackageTestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    public function testWarnsOnceWhenTheDriverIsUnavailable(): void
    {
        $logger = $this->logger();
        $logger->expects(self::once())->method('warning')->with(
            'SmartJudge driver is unavailable.',
            [
                'driver' => 'typesafe:jev-1.13.0',
                'status' => 401,
                'reason' => 'Responded with status 401.',
                'scope' => 'recurring',
            ],
        );
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');
    }

    public function testDoesNotWarnWhileTheDriverIsPaused(): void
    {
        $this->logger()->expects(self::once())->method('warning');
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');
        $this->askUnavailable('recurring');
        $this->askUnavailable('labels');
    }

    public function testDoesNotLogAnswersOrCacheHits(): void
    {
        $this->logger()->expects(self::never())->method(self::anything());
        $this->answer(0.8);

        $this->ask('recurring');
        $this->ask('recurring');
    }

    public function testDoesNotWarnWhenTheLogIsOff(): void
    {
        config()->set('smart-judge.log.enabled', false);
        $this->logger()->expects(self::never())->method(self::anything());
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');
    }

    public function testWarnsOnTheConfiguredChannel(): void
    {
        config()->set('smart-judge.log.channel', 'smart');
        $this->channel('smart')->expects(self::once())->method('warning');
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');
    }

    public function testWarnsOnTheDefaultChannelWithoutAConfiguredOne(): void
    {
        config()->set('smart-judge.log.channel', null);
        $this->channel(null)->expects(self::once())->method('warning');
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');
    }

    private function logger(): LoggerInterface&MockObject
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->app->instance('smart-judge.logger', $logger);

        return $logger;
    }

    /**
     * The logger of a channel, picked from the app's log manager the way `smart-judge.logger` does.
     */
    private function channel(?string $name): LoggerInterface&MockObject
    {
        $logger = $this->createMock(LoggerInterface::class);
        $log = $this->createMock(LogManager::class);
        $log->expects(self::once())->method('channel')->with($name)->willReturn($logger);
        $this->app->instance('log', $log);

        return $logger;
    }

    /**
     * @return array<int|string, float>
     */
    private function ask(string $scope): array
    {
        $judge = $this->app->make(JudgeFactory::class)->make($scope);
        self::assertNotNull($judge);

        return $judge->ask([new Subject(3, ['description' => 'netflix'])], 'transaction', $this->question());
    }

    private function askUnavailable(string $scope): void
    {
        try {
            $this->ask($scope);
            self::fail('Expected the judge to be unavailable.');
        } catch (JudgeUnavailable) {
            // the consumer falls back; the log is what is tested
        }
    }
}
