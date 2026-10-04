<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Orchestra\Testbench\TestCase;
use Override;
use Potato\SmartJudge\Domain\Question;
use Potato\SmartJudge\Laravel\SmartJudgeServiceProvider;
use Psr\Http\Message\RequestInterface;

/**
 * The package in an app, with `smart-judge.http` stubbed so every request to TypeSafe can be answered and inspected.
 */
abstract class PackageTestCase extends TestCase
{
    protected MockHandler $responses;

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    protected array $history = [];

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [SmartJudgeServiceProvider::class];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        $app->instance('smart-judge.http', new Client(['handler' => $stack]));

        $app['config']->set('smart-judge.drivers.typesafe.key', 'secret-key');
        $app['config']->set('smart-judge.drivers.typesafe.base_url', 'https://jev.example.org/v1');
    }

    protected function question(): Question
    {
        return new Question('Is `%s` a recurring transaction?', 'A commitment that repeats.', 'One-off spending.');
    }
}
