<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\ConnectionClosedException;
use SQLCraft\Exceptions\SyntaxErrorException;
use SQLCraft\Exceptions\StreamingResultException;
use SQLCraft\ValueObjects\Identifier;

final class PdoConnectionTest extends TestCase
{
    private ?PdoConnection $connection = null;

    #[\Override]
    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('sqlite');
        $platform->method('quoteIdentifier')->willReturnCallback(
            static fn (Identifier $identifier): string => '"' . $identifier->name . '"',
        );
        $platform->method('quoteValue')->willReturn('quoted');

        $this->connection = new PdoConnection(
            $pdo,
            $platform,
            new PdoExceptionTranslator(),
            'test',
            'app',
        );
    }

    public function testItExecutesQueriesInBufferedAndStreamingModes(): void
    {
        $this->connection()->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $result = $this->connection()->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);

        self::assertSame('app', $this->connection()->getDatabaseName());
        self::assertSame(1, $result->affectedRows);
        self::assertSame('1', (string) $result->lastInsertId);
        self::assertSame(1, $this->connection()->affectedRows());

        $buffered = $this->connection()->query('SELECT id, name FROM users');
        self::assertFalse($buffered->isStreaming());
        self::assertSame([['id' => 1, 'name' => 'Ada']], $buffered->fetchAll());

        $streaming = $this->connection()->query('SELECT id, name FROM users', streaming: true);
        self::assertTrue($streaming->isStreaming());
        self::assertSame(['id' => 1, 'name' => 'Ada'], $streaming->fetchAssoc());
        self::assertNull($streaming->fetchAssoc());
    }

    public function testItTranslatesSyntaxErrorsAndRejectsUseAfterClose(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->connection()->query('SELEC invalid');
    }

    public function testItRejectsOperationsAfterClose(): void
    {
        $this->connection()->close();

        self::assertFalse($this->connection()->isConnected());
        self::assertFalse($this->connection()->ping());

        $this->expectException(ConnectionClosedException::class);
        $this->connection()->execute('SELECT 1');
    }

    public function testItUsesSavepointsForNestedTransactions(): void
    {
        $this->connection()->execute('CREATE TABLE values_table (value TEXT NOT NULL)');

        $outer = $this->connection()->beginTransaction();
        $this->connection()->execute("INSERT INTO values_table (value) VALUES ('outer')");
        $inner = $this->connection()->beginTransaction();
        $this->connection()->execute("INSERT INTO values_table (value) VALUES ('inner')");
        $inner->rollback();
        $outer->commit();

        self::assertSame([['value' => 'outer']], $this->connection()->query('SELECT value FROM values_table')->fetchAll());
    }

    public function testItExecutesPreparedStatementsAndClosesThem(): void
    {
        $this->connection()->execute('CREATE TABLE users (name TEXT NOT NULL)');
        $statement = $this->connection()->prepare('INSERT INTO users (name) VALUES (?)');
        $statement->execute(['Grace']);
        $statement->close();

        $this->expectException(ConnectionClosedException::class);
        $statement->execute(['Hopper']);
    }

    private function connection(): PdoConnection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    public function testStreamingResultsCannotBeCounted(): void
    {
        $streaming = $this->connection()->query('SELECT 1 AS value', streaming: true);

        $this->expectException(StreamingResultException::class);
        $streaming->count();
    }
}
