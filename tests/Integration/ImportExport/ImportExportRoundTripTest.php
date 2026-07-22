<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\ImportExport;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Import\ImportSourceInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Driver\PostgreSQLDriver;
use SQLCraft\Execution\BatchExecutor;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Export\CsvFormatWriter;
use SQLCraft\Export\DumpOptions;
use SQLCraft\Export\DumpScope;
use SQLCraft\Export\Exporter;
use SQLCraft\Export\SqlFormatWriter;
use SQLCraft\Export\StringBufferSink;
use SQLCraft\Export\TableSectionStyle;
use SQLCraft\Import\CsvImporter;
use SQLCraft\Import\CsvImportOptions;
use SQLCraft\Import\Importer;
use SQLCraft\Import\ImportOptions;
use SQLCraft\Metadata\ColumnInspector;
use SQLCraft\Metadata\ExportSource;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\Metadata\TableInspector;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\PostgreSQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\StatementSplitter;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class ImportExportRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function engineProvider(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('engineProvider')]
    public function test_sql_export_and_import_round_trips_table_data(string $engine): void
    {
        if ($engine !== 'sqlite' && getenv('SQLCRAFT_RUN_ENGINE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set SQLCRAFT_RUN_ENGINE_INTEGRATION=1 with engine services running.');
        }

        $connection = $this->connection($engine);
        $table = 'sqlcraft_m7_roundtrip';
        $quoted = $connection->quoteIdentifier($table);
        $connection->execute('DROP TABLE IF EXISTS '.$quoted);
        $connection->execute('CREATE TABLE '.$quoted.' (id INTEGER PRIMARY KEY, label TEXT NOT NULL, note TEXT)');
        $connection->execute('INSERT INTO '.$quoted.' (id, label, note) VALUES (?, ?, ?)', [1, 'a, "quoted"', null]);
        $connection->execute('INSERT INTO '.$quoted.' (id, label, note) VALUES (?, ?, ?)', [2, 'plain', 'value']);

        try {
            $source = $this->source($connection);
            $sink = new StringBufferSink;
            $options = new DumpOptions('sql', DumpScope::table($connection->getDatabaseName() ?? 'main', $table));
            (new Exporter($source, new QueryExecutor, new SqlFormatWriter($connection)))->export($connection, $sink, $options);
            $dump = $sink->contents();

            $connection->execute('DROP TABLE '.$quoted);
            $importSource = $this->sourceFromString($dump);
            $result = (new Importer(new StatementSplitter, new BatchExecutor(new QueryExecutor)))->import(
                $connection,
                $importSource,
                new ImportOptions,
            );

            self::assertSame(3, $result->statementsExecuted);
            self::assertSame([
                ['id' => 1, 'label' => 'a, "quoted"', 'note' => null],
                ['id' => 2, 'label' => 'plain', 'note' => 'value'],
            ], $connection->query('SELECT id, label, note FROM '.$quoted.' ORDER BY id')->fetchAll());
        } finally {
            $connection->execute('DROP TABLE IF EXISTS '.$quoted);
            $connection->close();
        }
    }

    public function test_large_sql_import_uses_bounded_memory(): void
    {
        $connection = $this->connection('sqlite');
        $connection->execute('CREATE TABLE sqlcraft_m7_large (id INTEGER PRIMARY KEY)');
        $path = tempnam(sys_get_temp_dir(), 'sqlcraft-m7-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        for ($id = 1; $id <= 20000; $id++) {
            fwrite($handle, 'INSERT INTO sqlcraft_m7_large (id) VALUES ('.$id.");\n");
        }
        fclose($handle);

        try {
            $source = new class($path) implements ImportSourceInterface {
                public function __construct(private readonly string $path) {}

                #[\Override]
                public function openStream(): mixed
                {
                    $stream = fopen($this->path, 'rb');
                    if ($stream === false) {
                        throw new \RuntimeException('Unable to open import fixture.');
                    }

                    return $stream;
                }

                #[\Override]
                public function getEstimatedSize(): int
                {
                    $size = filesize($this->path);

                    return $size === false ? 0 : $size;
                }
            };
            memory_reset_peak_usage();
            $before = memory_get_usage(true);
            $result = (new Importer(new StatementSplitter, new BatchExecutor(new QueryExecutor)))->import(
                $connection,
                $source,
                new ImportOptions,
            );
            $peakDelta = memory_get_peak_usage(true) - $before;

            self::assertSame(20000, $result->statementsExecuted);
            self::assertLessThan(16 * 1024 * 1024, $peakDelta);
            $count = $connection->query('SELECT COUNT(*) FROM sqlcraft_m7_large')->fetchRow();
            self::assertNotNull($count);
            self::assertSame(20000, $count[0]);
        } finally {
            unlink($path);
            $connection->close();
        }
    }

    public function test_csv_round_trip_preserves_delimited_null_and_binary_values_on_sqlite(): void
    {
        $connection = $this->connection('sqlite');
        $table = 'sqlcraft_m7_csv';
        $quoted = $connection->quoteIdentifier($table);
        $connection->execute('CREATE TABLE '.$quoted.' (id INTEGER PRIMARY KEY, label TEXT, payload BLOB, note TEXT)');
        $connection->execute('INSERT INTO '.$quoted.' (id, label, payload, note) VALUES (?, ?, ?, ?)', [1, 'a, "quoted"', "\x00\x01\xFF", null]);

        try {
            $source = $this->source($connection);
            $sink = new StringBufferSink;
            (new Exporter($source, new QueryExecutor, new CsvFormatWriter))->export(
                $connection,
                $sink,
                new DumpOptions('csv', DumpScope::table('main', $table), tableStyle: TableSectionStyle::None),
            );
            $csv = $sink->contents();
            $connection->execute('DELETE FROM '.$quoted);

            $result = (new CsvImporter(new ColumnInspector(new SqliteMetadataFactory)))->importCsv(
                $connection,
                new QualifiedName(new Identifier($table)),
                $this->sourceFromString($csv),
                new CsvImportOptions(wrapInTransaction: false),
            );

            self::assertSame(1, $result->statementsExecuted);
            self::assertSame([
                ['id' => 1, 'label' => 'a, "quoted"', 'payload' => "\x00\x01\xFF", 'note' => null],
            ], $connection->query('SELECT id, label, payload, note FROM '.$quoted)->fetchAll());
        } finally {
            $connection->execute('DROP TABLE IF EXISTS '.$quoted);
            $connection->close();
        }
    }

    private function connection(string $engine): ConnectionInterface
    {
        if ($engine === 'sqlite') {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return new PdoConnection($pdo, new SqlitePlatform, new PdoExceptionTranslator, databaseName: 'main');
        }

        $factory = new PdoConnectionFactory(new PdoExceptionTranslator);

        return match ($engine) {
            'mysql' => (new MySQLDriver($factory, new MySQLPlatform))->connect(new ConnectionParameters(host: 'mysql', port: 3306, database: 'sqlcraft_test', username: 'sqlcraft', password: 'secret')),
            'mariadb' => (new MySQLDriver($factory, new MariaDbPlatform))->connect(new ConnectionParameters(host: 'mariadb', port: 3306, database: 'sqlcraft_test', username: 'sqlcraft', password: 'secret')),
            'pgsql' => (new PostgreSQLDriver($factory, new PostgreSQLPlatform))->connect(new ConnectionParameters(host: 'postgres', port: 5432, database: 'sqlcraft_test', username: 'sqlcraft', password: 'secret')),
            default => throw new \InvalidArgumentException('Unknown engine: '.$engine),
        };
    }

    private function source(ConnectionInterface $connection): ExportSource
    {
        $factory = match ($connection->getPlatformName()) {
            'mysql', 'mariadb' => new MySQLMetadataFactory,
            'pgsql' => new PostgreSQLMetadataFactory,
            'sqlite' => new SqliteMetadataFactory,
            default => throw new \InvalidArgumentException('Unknown platform.'),
        };

        return new ExportSource(new TableInspector($factory), new ColumnInspector($factory));
    }

    private function sourceFromString(string $contents): ImportSourceInterface
    {
        return new class($contents) implements ImportSourceInterface {
            public function __construct(private readonly string $contents) {}

            #[\Override]
            public function openStream(): mixed
            {
                $stream = fopen('php://memory', 'r+');
                if ($stream === false) {
                    throw new \RuntimeException('Unable to create memory stream.');
                }
                fwrite($stream, $this->contents);
                rewind($stream);

                return $stream;
            }

            #[\Override]
            public function getEstimatedSize(): int
            {
                return strlen($this->contents);
            }
        };
    }
}
