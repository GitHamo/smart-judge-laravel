<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Potato\SmartJudge\Domain\Driver;
use Potato\SmartJudge\Domain\Subject;
use Potato\SmartJudge\Laravel\JudgeFactory;
use Potato\SmartJudge\Laravel\SmartJudgeServiceProvider;
use Psr\Log\LoggerInterface;

final class JudgeFactoryTest extends PackageTestCase
{
    public function testMakesAJudgeThatAsksTypeSafeWithTheConfiguredDriver(): void
    {
        config()->set('smart-judge.drivers.typesafe.model', 'jev-2.0.0');
        config()->set('smart-judge.drivers.typesafe.timeout', 9);
        $this->responses->append(new Response(200, [], json_encode([
            'answers' => ['transaction_3' => ['type' => 'noul', 'noul' => 0.8]],
        ], JSON_THROW_ON_ERROR)));

        $judge = $this->app->make(JudgeFactory::class)->make('recurring');

        self::assertNotNull($judge);
        self::assertSame([3 => 0.8], $judge->ask([new Subject(3, ['description' => 'netflix'])], 'transaction', $this->question()));
        self::assertCount(1, $this->history);

        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://jev.example.org/v1/systemone', (string) $request->getUri());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame('jev-2.0.0', $body['model']);
        self::assertSame(9.0, $this->history[0]['options']['timeout']);
    }

    public function testMakesNoJudgeWhenDisabled(): void
    {
        config()->set('smart-judge.enabled', false);

        self::assertNull($this->app->make(JudgeFactory::class)->make('recurring'));
    }

    public function testMakesNoJudgeWhenTheDriverHasNoKey(): void
    {
        config()->set('smart-judge.drivers.typesafe.key', '');

        self::assertNull($this->app->make(JudgeFactory::class)->make('recurring'));
    }

    public function testMakesAJudgeWithADriverRegisteredByName(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('answer')->willReturn(['transaction_3' => 0.4]);
        $given = null;

        $this->app->make(JudgeFactory::class)->extend('local', static function (array $config) use ($driver, &$given): Driver {
            $given = $config;

            return $driver;
        });
        config()->set('smart-judge.driver', 'local');
        config()->set('smart-judge.drivers.local', ['url' => 'http://localhost:8080']);

        $judge = $this->app->make(JudgeFactory::class)->make('recurring');

        self::assertNotNull($judge);
        self::assertSame([3 => 0.4], $judge->ask([new Subject(3, [])], 'transaction', $this->question()));
        self::assertSame(['url' => 'http://localhost:8080'], $given);
        self::assertCount(0, $this->history);
    }

    public function testMakesNoJudgeWhenADriverRegisteredByNameIsNotConfigured(): void
    {
        $this->app->make(JudgeFactory::class)->extend('local', static fn (array $config): ?Driver => null);
        config()->set('smart-judge.driver', 'local');

        self::assertNull($this->app->make(JudgeFactory::class)->make('recurring'));
    }

    public function testRejectsADriverThatIsNotRegistered(): void
    {
        config()->set('smart-judge.driver', 'unknown');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SmartJudge driver "unknown" is not registered.');

        $this->app->make(JudgeFactory::class)->make('recurring');
    }

    public function testRejectsATypeSafeDriverWithAKeyButNoModel(): void
    {
        config()->set('smart-judge.drivers.typesafe.model', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SmartJudge driver "typesafe" needs a model, a base_url and a timeout.');

        $this->app->make(JudgeFactory::class)->make('recurring');
    }

    public function testMakesAJudgeWithTheDriverBoundInTheContainer(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('answer')->willReturn(['transaction_3' => 0.3]);
        $this->app->instance('smart-judge.driver', $driver);

        $judge = $this->app->make(JudgeFactory::class)->make('recurring');

        self::assertNotNull($judge);
        self::assertSame([3 => 0.3], $judge->ask([new Subject(3, [])], 'transaction', $this->question()));
        self::assertCount(0, $this->history);
    }

    public function testMergesTheConfigWithItsDefaults(): void
    {
        self::assertTrue(config('smart-judge.enabled'));
        self::assertSame('typesafe', config('smart-judge.driver'));
        self::assertSame('jev-1.13.0', config('smart-judge.drivers.typesafe.model'));
        self::assertSame(5, config('smart-judge.drivers.typesafe.timeout'));
        self::assertNull(config('smart-judge.cache.store'));
        self::assertSame(6 * 60 * 60, config('smart-judge.cache.ttl'));
        self::assertSame(60, config('smart-judge.cache.unavailable_ttl'));
        self::assertSame([], config('smart-judge.scopes'));
        self::assertTrue(config('smart-judge.log.enabled'));
        self::assertNull(config('smart-judge.log.channel'));
    }

    public function testPublishesTheConfig(): void
    {
        $paths = ServiceProvider::pathsToPublish(SmartJudgeServiceProvider::class, 'smart-judge-config');

        self::assertCount(1, $paths);
        self::assertFileExists((string) array_key_first($paths));
        self::assertSame(config_path('smart-judge.php'), array_values($paths)[0]);
    }

    public function testBindsTheCacheStoreAndTheLogChannelFromConfig(): void
    {
        config()->set('smart-judge.cache.store', 'array');
        config()->set('smart-judge.log.channel', 'null');

        self::assertInstanceOf(Repository::class, $this->app->make('smart-judge.cache'));
        self::assertSame($this->app['cache']->store('array'), $this->app->make('smart-judge.cache'));
        self::assertInstanceOf(LoggerInterface::class, $this->app->make('smart-judge.logger'));
    }
}
