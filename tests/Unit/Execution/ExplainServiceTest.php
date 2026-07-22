<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Execution\ExplainService;

final class ExplainServiceTest extends TestCase
{
    public function test_builds_platform_explain_sql_and_returns_rows(): void
    {
        $platform = self::createMock(PlatformInterface::class);
        $platform->expects(self::once())->method('getExplainSql')->with('SELECT * FROM users WHERE id = ?', true)->willReturn('EXPLAIN ANALYZE SELECT * FROM users WHERE id = ?');
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([['plan' => 'scan']]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatform')->willReturn($platform);
        $connection->expects(self::once())->method('getPlatformName')->willReturn('sqlite');
        $connection->expects(self::once())->method('query')->with('EXPLAIN ANALYZE SELECT * FROM users WHERE id = ?', [7], false)->willReturn($result);

        $explain = (new ExplainService)->explain($connection, 'SELECT * FROM users WHERE id = ?', [7], true);

        self::assertSame('sqlite', $explain->engine);
        self::assertSame([['plan' => 'scan']], $explain->rows);
        self::assertGreaterThanOrEqual(0.0, $explain->elapsedMs);
    }
}
