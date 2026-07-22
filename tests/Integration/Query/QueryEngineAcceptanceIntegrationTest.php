<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\Query;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Connection\TransactionManager;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Query\TableStatusProviderInterface;
use SQLCraft\Driver\MySQLDriver;
use SQLCraft\Execution\BatchExecutor;
use SQLCraft\Execution\QueryExecutor;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\Paginator;
use SQLCraft\Query\SelectQuery;
use SQLCraft\Query\SelectQueryRenderer;
use SQLCraft\Query\StatementSplitter;
use SQLCraft\ValueObjects\ConnectionParameters;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class QueryEngineAcceptanceIntegrationTest extends TestCase
{
    public function test_streaming_query_keeps_memory_bounded_on_large_sqlite_fixture(): void
    {
        $connection = $this->sqliteConnection();
        $connection->execute('CREATE TABLE rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $connection->execute(<<<'SQL'
            WITH RECURSIVE sequence(id) AS (
                SELECT 1
                UNION ALL
                SELECT id + 1 FROM sequence WHERE id < 1000000
            )
            INSERT INTO rows (id, value)
            SELECT id, 'payload' FROM sequence
            SQL);

        gc_collect_cycles();
        $before = memory_get_usage(true);
        $result = (new QueryExecutor)->query($connection, 'SELECT id, value FROM rows ORDER BY id');
        $rows = 0;
        while ($result->fetchAssoc() !== null) {
            $rows++;
        }

        self::assertSame(1000000, $rows);
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    public function test_transactional_rollback_is_exercised_through_query_executor(): void
    {
        $connection = $this->sqliteConnection();
        $connection->execute('CREATE TABLE ledger (value TEXT NOT NULL)');
        $executor = new QueryExecutor;

        try {
            (new TransactionManager)->transactional($connection, function (ConnectionInterface $connection) use ($executor): never {
                $executor->execute($connection, 'INSERT INTO ledger (value) VALUES (?)', ['transient']);
                throw new \RuntimeException('deliberate rollback');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('deliberate rollback', $exception->getMessage());
        }

        self::assertSame([], $connection->query('SELECT value FROM ledger')->fetchAll());
    }

    public function test_paginator_uses_approximate_table_status_rows_against_sqlite(): void
    {
        $connection = $this->sqliteConnection();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->execute('INSERT INTO users (name) VALUES (?)', ['Ada']);
        $connection->execute('INSERT INTO users (name) VALUES (?)', ['Grace']);

        $status = new class implements TableStatusProviderInterface {
            #[\Override]
            public function getApproximateRowCount(ConnectionInterface $connection, QualifiedName $table): int
            {
                return 2500000;
            }
        };
        $table = new QualifiedName(new Identifier('users'));
        $page = (new Paginator(new QueryExecutor, new SelectQueryRenderer(new SqlitePlatform), $status))
            ->paginate($connection, new SelectQuery($table), new PaginationParams(1, 1));

        self::assertSame(2500000, $page->totalRows);
        self::assertTrue($page->totalApprox);
        self::assertTrue($page->hasMore);
    }

    public function test_delimiter_batch_creates_routine_against_mysql_when_enabled(): void
    {
        if (getenv('SQLCRAFT_RUN_ENGINE_INTEGRATION') !== '1') {
            self::markTestSkipped('Set SQLCRAFT_RUN_ENGINE_INTEGRATION=1 with MySQL running.');
        }

        $connection = (new MySQLDriver(
            new PdoConnectionFactory(new PdoExceptionTranslator),
            new MySQLPlatform,
        ))->connect(new ConnectionParameters(
            host: $this->environment('SQLCRAFT_MYSQL_HOST', 'mysql'),
            port: (int) $this->environment('SQLCRAFT_MYSQL_PORT', '3306'),
            database: $this->environment('SQLCRAFT_MYSQL_DB', 'sqlcraft_test'),
            username: $this->environment('SQLCRAFT_MYSQL_USER', 'sqlcraft'),
            password: $this->environment('SQLCRAFT_MYSQL_PASS', 'secret'),
        ));
        $connection->execute('DROP PROCEDURE IF EXISTS sqlcraft_acceptance_routine');

        try {
            $batch = (new StatementSplitter)->split(<<<'SQL'
                DELIMITER $$
                CREATE PROCEDURE sqlcraft_acceptance_routine()
                BEGIN
                    SELECT 1 AS first_result;
                    SELECT 2 AS second_result;
                END$$
                DELIMITER ;
                SQL);
            $results = iterator_to_array((new BatchExecutor(new QueryExecutor))->executeBatch($connection, $batch));

            self::assertCount(1, $results);
            self::assertNull($results[0]->error);
            self::assertNotNull($results[0]->result);
            $procedure = $connection->query('SHOW PROCEDURE STATUS LIKE "sqlcraft_acceptance_routine"')->fetchAssoc();
            self::assertNotNull($procedure);
            self::assertSame('sqlcraft_acceptance_routine', $procedure['Name'] ?? null);
        } finally {
            $connection->execute('DROP PROCEDURE IF EXISTS sqlcraft_acceptance_routine');
        }
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }

    private function sqliteConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new PdoConnection($pdo, new SqlitePlatform, new PdoExceptionTranslator, databaseName: 'main');
    }
}
