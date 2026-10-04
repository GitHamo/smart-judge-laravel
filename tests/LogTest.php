<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
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

    public function testDoesNotWarnWhileTheDriverIsNotAsked(): void
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
        config()->set('logging.channels.smart', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('logging.channels.other', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('logging.default', 'other');
        config()->set('smart-judge.log.channel', 'smart');
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');

        self::assertTrue($this->handler('smart')->hasWarning('SmartJudge driver is unavailable.'));
        self::assertFalse($this->handler('other')->hasWarningRecords());
    }

    public function testWarnsOnTheDefaultChannelWithoutAConfiguredOne(): void
    {
        config()->set('logging.channels.other', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('logging.default', 'other');
        config()->set('smart-judge.log.channel', null);
        $this->responses->append(new Response(401));

        $this->askUnavailable('recurring');

        self::assertTrue($this->handler('other')->hasWarning('SmartJudge driver is unavailable.'));
    }

    private function logger(): LoggerInterface&MockObject
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->app->instance('smart-judge.logger', $logger);

        return $logger;
    }

    private function handler(string $channel): TestHandler
    {
        $logger = $this->app['log']->channel($channel)->getLogger();
        self::assertInstanceOf(Monolog::class, $logger);
        $handler = $logger->getHandlers()[0];
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    private function answer(float $probability): void
    {
        $this->responses->append(new Response(200, [], json_encode([
            'answers' => ['transaction_3' => ['type' => 'noul', 'noul' => $probability]],
        ], JSON_THROW_ON_ERROR)));
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
