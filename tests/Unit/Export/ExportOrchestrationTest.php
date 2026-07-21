<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Export;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Export\ExportSourceInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\DataStyle;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Export\TableDumper;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ServerVersion;

final class ExportOrchestrationTest extends TestCase
{
    public function testTableDumperStreamsRowsInConfiguredBatches(): void
    {
        $connection = $this->connection();
        $table = new TableStatus('orders');
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::once())->method('getColumns')->with($connection, 'orders', null)->willReturn($this->columns());
        $source->expects(self::once())->method('getTableDdl')->with($connection, 'orders', null)->willReturn([]);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('query')->with($connection, 'SELECT * FROM "orders"', [], false)->willReturn($this->resultSet([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]));

        $sink = new StringBufferSink();
        $options = new DumpOptions('csv', DumpScope::table('shop', 'orders'), batchSize: 2);
        $rows = (new TableDumper($source, $executor))->dump($connection, $table, new CsvFormatWriter(), $sink, $options);

        self::assertSame(3, $rows);
        self::assertSame("id\r\n1\r\n2\r\n3\r\n", $sink->contents());
    }

    public function testExporterSelectsWriterAndPreservesSelectedTableOrder(): void
    {
        $connection = $this->connection();
        $first = new TableStatus('first');
        $second = new TableStatus('second');
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::exactly(2))->method('getTableStatus')->willReturnMap([
            [$connection, 'first', null, $first],
            [$connection, 'second', null, $second],
        ]);
        $source->expects(self::exactly(2))->method('getColumns')->willReturn($this->columns());
        $source->expects(self::exactly(2))->method('getTableDdl')->willReturn([]);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::exactly(2))->method('query')->willReturnOnConsecutiveCalls(
            $this->resultSet([['id' => 1]]),
            $this->resultSet([['id' => 2]]),
        );

        $sink = new StringBufferSink();
        $options = new DumpOptions('csv', DumpScope::tables('shop', ['first', 'second']));
        (new Exporter($source, $executor, new CsvFormatWriter()))->export($connection, $sink, $options);

        self::assertSame("id\r\n1\r\nid\r\n2\r\n", $sink->contents());
    }

    public function testFilteredScopeUsesCallerSqlAndSkipsViewDataPolicy(): void
    {
        $connection = $this->connection();
        $table = new TableStatus('orders');
        $source = self::createMock(ExportSourceInterface::class);
        $source->method('getTableStatus')->willReturn($table);
        $source->expects(self::once())->method('getColumns')->with($connection, 'orders', null)->willReturn($this->columns());
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::once())->method('query')->with($connection, 'SELECT id FROM "orders" WHERE id > 1', [], false)->willReturn($this->resultSet([['id' => 2]]));

        $sink = new StringBufferSink();
        $options = new DumpOptions(
            'csv',
            DumpScope::filteredResult('shop', 'orders', 'SELECT id FROM "orders" WHERE id > 1'),
            dataStyle: DataStyle::Insert,
        );
        (new Exporter($source, $executor, new CsvFormatWriter()))->export($connection, $sink, $options);

        self::assertSame("id\r\n2\r\n", $sink->contents());
    }

    public function testUnknownFormatFailsBeforeWriting(): void
    {
        $source = self::createMock(ExportSourceInterface::class);
        $executor = self::createMock(QueryExecutorInterface::class);
        $sink = new StringBufferSink();
        $options = new DumpOptions('yaml', DumpScope::database('shop'));

        $this->expectException(\InvalidArgumentException::class);
        (new Exporter($source, $executor, new CsvFormatWriter()))->export($this->connection(), $sink, $options);
        self::assertSame('', $sink->contents());
    }

    public function testTableDumperEmitsMysqlAutoIncrementStateWhenRequested(): void
    {
        $connection = $this->connection('mysql', new MySQLPlatform());
        $table = new TableStatus('orders', autoIncrement: 42);
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::once())->method('getTableDdl')->willReturn([]);
        $executor = self::createMock(QueryExecutorInterface::class);

        $sink = new StringBufferSink();
        (new TableDumper($source, $executor))->dump(
            $connection,
            $table,
            new SqlFormatWriter($connection),
            $sink,
            new DumpOptions('sql', DumpScope::table('shop', 'orders'), dataStyle: DataStyle::None),
        );

        self::assertStringContainsString('ALTER TABLE "orders" AUTO_INCREMENT = 42;', $sink->contents());
    }

    public function testTableDumperEmitsRequestedTriggerAndRoutineDdl(): void
    {
        $connection = $this->connection('mysql', new MySQLPlatform());
        $table = new TableStatus('orders');
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::once())->method('getTableDdl')->willReturn(['CREATE TABLE "orders" ("id" INT)']);
        $source->expects(self::once())->method('getTriggerDdl')->with($connection, 'orders', null)->willReturn(['CREATE TRIGGER audit']);
        $source->expects(self::once())->method('getRoutineDdl')->with($connection, null)->willReturn(['CREATE FUNCTION total']);

        $sink = new StringBufferSink();
        (new TableDumper($source, self::createMock(QueryExecutorInterface::class)))->dump(
            $connection,
            $table,
            new SqlFormatWriter($connection),
            $sink,
            new DumpOptions(
                'sql',
                DumpScope::table('shop', 'orders'),
                dataStyle: DataStyle::None,
                includeTriggers: true,
                includeRoutines: true,
            ),
        );

        self::assertStringContainsString('CREATE TRIGGER audit;', $sink->contents());
        self::assertStringContainsString('CREATE FUNCTION total;', $sink->contents());
    }

    public function testTableDumperDoesNotEmitOptionalDdlWhenFlagsAreFalse(): void
    {
        $connection = $this->connection('mysql', new MySQLPlatform());
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::once())->method('getTableDdl')->willReturn([]);
        $source->expects(self::never())->method('getTriggerDdl');
        $source->expects(self::never())->method('getRoutineDdl');

        $sink = new StringBufferSink();
        (new TableDumper($source, self::createMock(QueryExecutorInterface::class)))->dump(
            $connection,
            new TableStatus('orders'),
            new SqlFormatWriter($connection),
            $sink,
            new DumpOptions('sql', DumpScope::table('shop', 'orders'), dataStyle: DataStyle::None),
        );

        self::assertStringNotContainsString('CREATE TRIGGER', $sink->contents());
        self::assertStringNotContainsString('CREATE FUNCTION', $sink->contents());
    }

    public function testTableDumperPreservesBatchBoundariesInSqlOutput(): void
    {
        $connection = $this->connection('mysql', new MySQLPlatform());
        $source = self::createMock(ExportSourceInterface::class);
        $source->method('getColumns')->willReturn($this->columns());
        $source->method('getTableDdl')->willReturn([]);
        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->method('query')->willReturn($this->resultSet([
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ]));

        $sink = new StringBufferSink();
        (new TableDumper($source, $executor))->dump(
            $connection,
            new TableStatus('orders'),
            new SqlFormatWriter($connection),
            $sink,
            new DumpOptions('sql', DumpScope::table('shop', 'orders'), batchSize: 2),
        );

        self::assertSame(2, substr_count($sink->contents(), 'INSERT INTO'));
    }

    public function testTableDumperTreatsCapabilityLookupFailureAsUnsupported(): void
    {
        /** @var ConnectionInterface&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('getDatabaseName')->willReturn('shop');
        $connection->method('getPlatformName')->willReturn('mysql');
        $connection->method('getPlatform')->willThrowException(new \RuntimeException('platform unavailable'));
        $source = self::createMock(ExportSourceInterface::class);
        $source->expects(self::once())->method('getTableDdl')->willReturn([]);
        $source->expects(self::never())->method('getTriggerDdl');

        (new TableDumper($source, self::createMock(QueryExecutorInterface::class)))->dump(
            $connection,
            new TableStatus('orders'),
            new SqlFormatWriter($connection),
            new StringBufferSink(),
            new DumpOptions(
                'sql',
                DumpScope::table('shop', 'orders'),
                dataStyle: DataStyle::None,
                includeTriggers: true,
            ),
        );
    }

    private function connection(string $platformName = 'sqlite', ?PlatformInterface $platform = null): ConnectionInterface
    {
        $connection = self::createMock(ConnectionInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');
        $connection->method('getDatabaseName')->willReturn('shop');
        $connection->method('getPlatformName')->willReturn($platformName);
        $connection->method('getPlatform')->willReturn($platform ?? new SqlitePlatform());
        $connection->method('getServerVersion')->willReturn(new ServerVersion('8.0.36'));

        return $connection;
    }

    /** @param list<array<string, mixed>> $rows */
    private function resultSet(array $rows): ResultInterface
    {
        $result = self::createMock(ResultInterface::class);
        $result->method('getIterator')->willReturn(new ArrayIterator($rows));

        return $result;
    }

    private function columns(): ColumnCollection
    {
        return new ColumnCollection([$this->column('id')]);
    }

    private function column(string $name): ColumnMeta
    {
        return new ColumnMeta(
            name: $name,
            dataType: new DataType('INTEGER'),
            nullable: false,
            autoIncrement: false,
            primary: false,
            generated: false,
            default: DefaultValue::nullValue(),
            collation: null,
            comment: null,
            onUpdate: null,
            privileges: [],
            origName: null,
            defaultConstraintName: null,
        );
    }
}
