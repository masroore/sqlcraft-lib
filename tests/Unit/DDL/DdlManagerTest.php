<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\TableRecreationMetadataProviderInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;
use SQLCraft\Contracts\Events\SchemaEventDispatcherInterface;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\CreateViewBuilder;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;
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

    public function testSqliteAlterUsesConfiguredRecreationStrategy(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('getPlatformName')->willReturn('sqlite');
        $transactions = self::createMock(TransactionManagerInterface::class);
        $transactions->expects(self::once())->method('transactional')->with(
            $connection,
            self::isInstanceOf(\Closure::class),
        );
        $metadata = self::createMock(TableRecreationMetadataProviderInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('executeDdl');
        $builder = (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))
            ->dropColumn(new Identifier('obsolete'));

        (new DdlManager($executor, new TableRecreationStrategy($transactions, $metadata)))
            ->execute($connection, $builder);
    }


    public function testExecuteDispatchesSchemaLifecycleEvents(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('executeDdl');
        $events = self::createMock(SchemaEventDispatcherInterface::class);
        $events->expects(self::once())->method('beforeSchemaChange')->willReturn(null);
        $events->expects(self::once())->method('beforeDdlExecuted')->willReturn(null);
        $events->expects(self::once())->method('afterDdlExecuted');
        $events->expects(self::once())->method('schemaChanged');

        (new DdlManager($executor, events: $events))->execute(
            $connection,
            new CreateViewBuilder(new QualifiedName(new Identifier('active_users')), 'SELECT 1'),
        );
    }

    public function testDdlCanBeCancelledBeforeExecution(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('executeDdl');
        $events = self::createMock(SchemaEventDispatcherInterface::class);
        $events->expects(self::once())->method('beforeSchemaChange')->willReturn('approval required');
        $events->expects(self::never())->method('beforeDdlExecuted');

        $this->expectException(OperationCancelledException::class);
        (new DdlManager($executor, events: $events))->execute(
            $connection,
            new CreateViewBuilder(new QualifiedName(new Identifier('active_users')), 'SELECT 1'),
        );
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
