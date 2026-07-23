<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\DDL\TableRecreationMetadataProviderInterface;
use SQLCraft\Contracts\Execution\TransactionManagerInterface;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\DDL\Definition\TableRecreationDefinition;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;
use SQLCraft\DTO\ExecutionResult;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class TableRecreationStrategyTest extends TestCase
{
    public function test_recreation_runs_transactional_create_copy_drop_rename_sequence(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        /** @var array<int, string> $executed */
        $executed = [];
        $connection = $this->connection([], $executed);
        $metadata = self::createMock(TableRecreationMetadataProviderInterface::class);
        $metadata->expects(self::once())->method('getDefinition')->with($connection, $table)->willReturn(
            new TableRecreationDefinition([$this->column('id'), $this->column('obsolete')]),
        );
        $transactions = self::createMock(TransactionManagerInterface::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (ConnectionInterface $connection, callable $callback): mixed => $callback($connection),
        );

        (new TableRecreationStrategy($transactions, $metadata))->execute(
            $connection,
            (new AlterTableBuilder($table))->dropColumn(new Identifier('obsolete')),
        );

        self::assertSame('PRAGMA foreign_keys = OFF', $executed[0]);
        $this->assertExecutedContains('CREATE TABLE "_sqlcraft_recreate_users_', $executed, 1);
        $this->assertExecutedContains('"id" INTEGER', $executed, 1);
        $this->assertExecutedContains('INSERT INTO "_sqlcraft_recreate_users_', $executed, 2);
        $this->assertExecutedContains('SELECT "id" FROM "users"', $executed, 2);
        self::assertSame('DROP TABLE "users"', $executed[3]);
        $this->assertExecutedContains('ALTER TABLE "_sqlcraft_recreate_users_', $executed, 4);
        self::assertSame('PRAGMA foreign_keys = ON', $executed[5]);
        self::assertCount(6, $executed);
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeyViolations
     * @param  array<int, string>  $executed
     */
    private function connection(array $foreignKeyViolations, array &$executed): ConnectionInterface
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('getPlatform')->willReturn(new SqlitePlatform());
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('execute')->willReturnCallback(function (string $sql) use (&$executed): ExecutionResult {
            $executed[] = $sql;

            return new ExecutionResult(0, 0, 0.0, $sql);
        });
        $result = self::createMock(ResultInterface::class);
        $result->method('fetchAll')->willReturn($foreignKeyViolations);
        $connection->method('query')->willReturn($result);

        return $connection;
    }

    /** @param array<int, string> $executed */
    private function assertExecutedContains(string $needle, array $executed, int $index): void
    {
        self::assertArrayHasKey($index, $executed);
        self::assertStringContainsString($needle, $executed[$index]);
    }

    private function column(string $name): ColumnDefinition
    {
        return new ColumnDefinition($name, new DataType('INTEGER'), true, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null);
    }
}
