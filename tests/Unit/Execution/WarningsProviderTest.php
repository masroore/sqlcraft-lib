<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\WarningCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Execution\WarningsProvider;

final class WarningsProviderTest extends TestCase
{
    public function test_returns_typed_mysql_warnings(): void
    {
        $result = self::createMock(ResultInterface::class);
        $result->expects(self::once())->method('fetchAll')->willReturn([
            ['Level' => 'Warning', 'Code' => 1265, 'Message' => 'Data truncated'],
            ['Level' => 'Note', 'Code' => 100, 'Message' => 'Note'],
        ]);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatformName')->willReturn('mysql');
        $connection->expects(self::once())->method('query')->with('SHOW WARNINGS', [], false)->willReturn($result);

        $warnings = (new WarningsProvider)->getWarnings($connection);

        self::assertInstanceOf(WarningCollection::class, $warnings);
        self::assertCount(2, $warnings);
        self::assertSame('Warning', $warnings->get(0)->level);
        self::assertSame(1265, $warnings->get(0)->code);
    }

    public function test_returns_empty_collection_for_unsupported_platforms(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatformName')->willReturn('sqlite');
        $connection->expects(self::never())->method('query');

        self::assertCount(0, (new WarningsProvider)->getWarnings($connection));
    }
}
