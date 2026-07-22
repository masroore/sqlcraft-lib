<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\Connection;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\QueryException;
use SQLCraft\Exceptions\SyntaxErrorException;
use SQLCraft\Exceptions\UniqueConstraintException;
use SQLCraft\ValueObjects\Identifier;

final class PdoConnectionIntegrationTest extends TestCase
{
    private ?string $databasePath = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->databasePath !== null) {
            @unlink($this->databasePath);
            $this->databasePath = null;
        }
    }

    public function test_in_memory_sqlite_supports_buffered_and_streaming_reads(): void
    {
        $connection = $this->connection('sqlite::memory:');
        $connection->execute('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $connection->execute('INSERT INTO records (value) VALUES (?)', ['one']);
        $connection->execute('INSERT INTO records (value) VALUES (?)', ['two']);

        self::assertSame(
            [['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']],
            $connection->query('SELECT id, value FROM records ORDER BY id')->fetchAll(),
        );

        $streaming = $connection->query('SELECT value FROM records ORDER BY id', streaming: true);
        self::assertSame(['value' => 'one'], $streaming->fetchAssoc());
        self::assertSame(['value' => 'two'], $streaming->fetchAssoc());
        self::assertNull($streaming->fetchAssoc());
    }

    public function test_file_sqlite_persists_rows_across_connections(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'sqlcraft_');
        if ($databasePath === false) {
            self::fail('Unable to create a temporary SQLite database path.');
        }

        $this->databasePath = $databasePath;
        $first = $this->connection('sqlite:' . $databasePath);
        $first->execute('CREATE TABLE records (value TEXT NOT NULL)');
        $first->execute('INSERT INTO records (value) VALUES (?)', ['persisted']);
        $first->close();

        $second = $this->connection('sqlite:' . $this->databasePath);
        self::assertSame([['value' => 'persisted']], $second->query('SELECT value FROM records')->fetchAll());
    }

    public function test_sqlite_failures_surface_as_typed_exceptions(): void
    {
        $connection = $this->connection('sqlite::memory:');
        $connection->execute('CREATE TABLE users (email TEXT UNIQUE NOT NULL)');
        $connection->execute('INSERT INTO users (email) VALUES (?)', ['ada@example.test']);

        try {
            $connection->execute('INSERT INTO users (email) VALUES (?)', ['ada@example.test']);
            self::fail('Expected a unique constraint exception.');
        } catch (UniqueConstraintException $exception) {
            self::assertSame('INSERT INTO users (email) VALUES (?)', $exception->sql);
        }

        try {
            $connection->query('SELEC invalid');
            self::fail('Expected a syntax exception.');
        } catch (SyntaxErrorException $exception) {
            self::assertSame('SELEC invalid', $exception->sql);
        }

        $this->expectException(QueryException::class);
        $connection->query('SELECT * FROM missing_table');
    }

    private function connection(string $dsn): PdoConnection
    {
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('sqlite');
        $platform->method('quoteIdentifier')->willReturnCallback(
            static fn (Identifier $identifier): string => '"' . $identifier->name . '"',
        );
        $platform->method('quoteValue')->willReturn('quoted');

        return new PdoConnection($pdo, $platform, new PdoExceptionTranslator);
    }
}
