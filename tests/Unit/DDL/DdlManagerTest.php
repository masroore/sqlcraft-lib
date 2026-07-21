<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\DDL\CreateViewBuilder;
use SQLCraft\DDL\DdlManager;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class DdlManagerTest extends TestCase
{
    public function testPreviewDoesNotExecute(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('executeDdl');
        $builder = new CreateViewBuilder(new QualifiedName(new Identifier('active_users')), 'SELECT 1');

        self::assertSame(['CREATE VIEW "active_users" AS SELECT 1'], (new DdlManager($executor))->preview($connection, $builder));
    }

    public function testExecuteRoutesEveryStatementThroughQueryExecutor(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('executeDdl')->with($connection, 'CREATE VIEW "active_users" AS SELECT 1');
        $builder = new CreateViewBuilder(new QualifiedName(new Identifier('active_users')), 'SELECT 1');

        (new DdlManager($executor))->execute($connection, $builder);
    }
}
