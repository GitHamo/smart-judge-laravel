<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Override;
use Potato\SmartJudge\Application\Judge;
use Potato\SmartJudge\Domain\JudgeUnavailable;
use Potato\SmartJudge\Domain\Subject;
use Potato\SmartJudge\Laravel\JudgeFactory;

final class CacheTest extends PackageTestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.other', ['driver' => 'array']);
    }

    public function testAsksOnceForTheSameQuestionInAScope(): void
    {
        $this->answer(0.8);

        self::assertSame([3 => 0.8], $this->ask('recurring'));
        self::assertSame([3 => 0.8], $this->ask('recurring'));
        self::assertCount(1, $this->history);
    }

    public function testAsksAgainForAnotherSubject(): void
    {
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');

        self::assertSame([3 => 0.2], $this->ask('recurring', ['description' => 'groceries']));
        self::assertCount(2, $this->history);
    }

    public function testAsksAgainInAnotherScope(): void
    {
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');

        self::assertSame([3 => 0.2], $this->ask('labels'));
        self::assertCount(2, $this->history);
    }

    public function testAsksAgainAfterTheModelChanged(): void
    {
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');
        config()->set('smart-judge.drivers.typesafe.model', 'jev-2.0.0');

        self::assertSame([3 => 0.2], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    public function testKeepsAnAnswerForTheGlobalTtl(): void
    {
        config()->set('smart-judge.cache.ttl', 600);
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');
        $this->travel(599)->seconds();
        $this->ask('recurring');
        self::assertCount(1, $this->history);

        $this->travel(2)->seconds();

        self::assertSame([3 => 0.2], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    public function testKeepsAnAnswerForTheTtlOfItsScope(): void
    {
        config()->set('smart-judge.cache.ttl', 600);
        config()->set('smart-judge.scopes.recurring.ttl', 60);
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');
        $this->travel(61)->seconds();

        self::assertSame([3 => 0.2], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    public function testKeepsAnswersInTheConfiguredStore(): void
    {
        config()->set('smart-judge.cache.store', 'other');
        $this->answer(0.8);

        $this->ask('recurring');
        $this->app['cache']->store('array')->flush();

        self::assertSame([3 => 0.8], $this->ask('recurring'));
        self::assertCount(1, $this->history);
    }

    public function testStopsAskingTheDriverInEveryScopeForAWhileAfterItWasUnavailable(): void
    {
        config()->set('smart-judge.cache.unavailable_ttl', 60);
        $this->responses->append(new Response(401));
        $this->answer(0.8);

        $this->assertUnavailable('recurring', 401);
        $this->assertUnavailable('labels', null);
        $this->travel(59)->seconds();
        $this->assertUnavailable('recurring', null);
        self::assertCount(1, $this->history);

        $this->travel(2)->seconds();

        self::assertSame([3 => 0.8], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    public function testKeepsAskingAnotherDriverAfterOneWasUnavailable(): void
    {
        $this->responses->append(new Response(401));
        $this->answer(0.8);

        $this->assertUnavailable('recurring', 401);
        config()->set('smart-judge.drivers.typesafe.model', 'jev-2.0.0');

        self::assertSame([3 => 0.8], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    public function testClearsTheAnswersOfOneScope(): void
    {
        $this->answer(0.8);
        $this->answer(0.6);
        $this->answer(0.2);

        $this->ask('recurring');
        $this->ask('labels');

        $this->artisan('smart-judge:clear', ['scope' => 'recurring'])->assertSuccessful();

        self::assertSame([3 => 0.2], $this->ask('recurring'));
        self::assertSame([3 => 0.6], $this->ask('labels'));
        self::assertCount(3, $this->history);
    }

    public function testClearsTheAnswersOfEveryScope(): void
    {
        $this->answer(0.8);
        $this->answer(0.6);
        $this->answer(0.3);
        $this->answer(0.2);

        $this->ask('recurring');
        $this->ask('labels');

        $this->artisan('smart-judge:clear')->assertSuccessful();

        self::assertSame([3 => 0.3], $this->ask('recurring'));
        self::assertSame([3 => 0.2], $this->ask('labels'));
        self::assertCount(4, $this->history);
    }

    public function testClearsTheAnswersInTheConfiguredStore(): void
    {
        config()->set('smart-judge.cache.store', 'other');
        $this->answer(0.8);
        $this->answer(0.2);

        $this->ask('recurring');

        $this->artisan('smart-judge:clear', ['scope' => 'recurring'])->assertSuccessful();

        self::assertSame([3 => 0.2], $this->ask('recurring'));
        self::assertCount(2, $this->history);
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array<int|string, float>
     */
    private function ask(string $scope, array $facts = ['description' => 'netflix']): array
    {
        return $this->judge($scope)->ask([new Subject(3, $facts)], 'transaction', $this->question());
    }

    private function judge(string $scope): Judge
    {
        $judge = $this->app->make(JudgeFactory::class)->make($scope);
        self::assertNotNull($judge);

        return $judge;
    }

    private function assertUnavailable(string $scope, ?int $status): void
    {
        try {
            $this->ask($scope);
            self::fail('Expected the judge to be unavailable.');
        } catch (JudgeUnavailable $e) {
            self::assertSame('typesafe:jev-1.13.0', $e->driver);
            self::assertSame($status, $e->status);
        }
    }
}
