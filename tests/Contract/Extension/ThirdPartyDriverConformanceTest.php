<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Contract\Extension;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInitializerInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Events\ConnectionEventDispatcherInterface;
use SQLCraft\Contracts\Execution\QueryHistoryEntry;
use SQLCraft\Contracts\Execution\QueryHistoryInterface;
use SQLCraft\Contracts\Execution\QueryInterceptorInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Export\SinkInterface;
use SQLCraft\Driver\DriverDefinition;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\Events\ConnectionOpenedEvent;
use SQLCraft\Execution\QueryRequest;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\SQLCraftBuilder;
use SQLCraft\Tests\Fixtures\Extension\FakeDriver;
use SQLCraft\Tests\Fixtures\Extension\FakeMetadataInspectorSetFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

require_once dirname(__DIR__, 2) . '/Fixtures/Extension/FakeDriver.php';

final class ThirdPartyDriverConformanceTest extends TestCase
{
    public function test_fake_engine_uses_public_composition_seams_end_to_end(): void
    {
        $metadata = new FakeMetadataInspectorSetFactory();
        $initializer = new RecordingInitializer();
        $opened = false;
        $history = new RecordingHistory();
        $builder = SQLCraftBuilder::defaults()
            ->registerDriver(new DriverDefinition(
                'fixturedb',
                static fn (ConnectionEventDispatcherInterface $events): FakeDriver => new FakeDriver($events),
                $metadata,
            ))
            ->registerDriverAlias('fixture', 'fixturedb')
            ->initializeConnection($initializer)
            ->interceptQueries(new class () implements QueryInterceptorInterface {
                #[\Override]
                public function intercept(QueryRequest $request): QueryRequest
                {
                    return $request->withSqlAndParams($request->sql . ' /* fixture */', $request->params);
                }
            })
            ->queryHistory($history)
            ->registerWriter('fixture', static fn (ConnectionInterface $connection): FormatWriterInterface => new FixtureWriter())
            ->listen(ConnectionOpenedEvent::class, static function () use (&$opened): void {
                $opened = true;
            });

        $factory = $builder->build();
        $driver = $factory->session(new ConnectionParameters(database: ':memory:', driver: 'fixture'));
        $driver->connection()->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $driver->connection()->execute('INSERT INTO items (value) VALUES (?)', ['ok']);
        $result = $driver->query('SELECT value FROM items');

        self::assertSame([['value' => 'ok']], $result->fetchAll());
        self::assertTrue($initializer->called);
        self::assertTrue($opened);
        self::assertSame('fixturedb', $driver->connection()->getPlatformName());
        self::assertSame(1, $metadata->created);
        self::assertSame('fixture', $driver->formats()->getWriter('fixture')->getFormatName());
        self::assertNotEmpty($history->entries);
        $last = array_pop($history->entries);
        self::assertInstanceOf(QueryHistoryEntry::class, $last);
        self::assertStringContainsString('/* fixture */', $last->sql);
        self::assertFalse($driver->connection()->getPlatform()->getCapabilitySet($driver->connection()->getServerVersion())->has(Capability::Kill));
        $sink = new StringBufferSink();
        $driver->export()->export($driver->connection(), $sink, new DumpOptions('fixture', DumpScope::table('main', 'items')));
        self::assertSame('', $sink->contents());
    }
}

final class RecordingInitializer implements ConnectionInitializerInterface
{
    public bool $called = false;

    #[\Override]
    public function initialize(ConnectionInterface $connection, ConnectionParameters $parameters): void
    {
        $this->called = true;
    }
}

final class RecordingHistory implements QueryHistoryInterface
{
    /** @var list<QueryHistoryEntry> */
    public array $entries = [];

    #[\Override]
    public function record(QueryHistoryEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<QueryHistoryEntry> */
    #[\Override]
    public function getRecent(string $database, int $limit = 100): array
    {
        return array_slice($this->entries, -$limit);
    }

    #[\Override]
    public function clearDatabase(string $database): void
    {
        $this->entries = [];
    }
}

final class FixtureWriter implements FormatWriterInterface
{
    #[\Override]
    public function getFormatName(): string
    {
        return 'fixture';
    }

    #[\Override]
    public function writeHeader(SinkInterface $sink, DumpOptions $options): void
    {
    }

    #[\Override]
    public function writeTableHeader(SinkInterface $sink, TableStatus $table, DumpOptions $options): void
    {
    }

    /** @param list<string> $ddlStatements */
    #[\Override]
    public function writeTableDdl(SinkInterface $sink, TableStatus $table, array $ddlStatements): void
    {
    }

    /** @param list<array<string, mixed>> $rows @param list<ColumnMeta> $columns */
    #[\Override]
    public function writeRows(SinkInterface $sink, TableStatus $table, array $rows, array $columns, DumpOptions $options): void
    {
    }

    #[\Override]
    public function writeTableFooter(SinkInterface $sink, TableStatus $table): void
    {
    }

    #[\Override]
    public function writeFooter(SinkInterface $sink, DumpOptions $options): void
    {
    }
}
