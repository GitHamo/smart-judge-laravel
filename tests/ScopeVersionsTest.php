<?php

declare(strict_types=1);

namespace Potato\SmartJudge\Laravel\Tests;

use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Potato\SmartJudge\Laravel\ScopeVersions;

final class ScopeVersionsTest extends TestCase
{
    public function testCountsAClearKeptAsAStringByTheStore(): void
    {
        // Redis keeps numbers unserialized and gives them back as strings
        $cache = $this->createMock(Repository::class);
        $cache->method('get')->willReturn('1');
        $cache->expects(self::once())->method('forever')->with('smart-judge:recurring:version', 2);

        $versions = new ScopeVersions($cache);

        self::assertSame('1.1', $versions->of('recurring'));
        $versions->clear('recurring');
    }
}
