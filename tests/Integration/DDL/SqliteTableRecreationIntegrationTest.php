<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\DDL;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Connection\TransactionManager;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\TableRecreationMetadataProviderInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\DdlManager;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\DDL\Definition\TableRecreationDefinition;
use SQLCraft\DDL\Sqlite\TableRecreationStrategy;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SqliteTableRecreationIntegrationTest extends TestCase
{
    public function test_drop_column_recreates_table_and_preserves_rows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection = new PdoConnection($pdo, new SqlitePlatform, new PdoExceptionTranslator, databaseName: 'main');
        $connection->execute('PRAGMA foreign_keys = ON');
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, obsolete TEXT, name TEXT NOT NULL)');
        $connection->execute('INSERT INTO users (obsolete, name) VALUES (?, ?)', ['remove', 'Ada']);

        $definition = new TableRecreationDefinition([
            new ColumnDefinition('id', new DataType('INTEGER'), true, false, true, false, DefaultValue::nullValue(), null, null, null, [], null, null),
            new ColumnDefinition('obsolete', new DataType('TEXT'), true, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null),
            new ColumnDefinition('name', new DataType('TEXT'), false, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null),
        ]);
        $provider = new class($definition) implements TableRecreationMetadataProviderInterface
        {
            public function __construct(private readonly TableRecreationDefinition $definition) {}

            #[\Override]
            public function getDefinition(ConnectionInterface $connection, QualifiedName $table): TableRecreationDefinition
            {
                return $this->definition;
            }
        };

        $executor = self::createMock(QueryExecutorInterface::class);
        $executor->expects(self::never())->method('executeDdl');
        (new DdlManager($executor, new TableRecreationStrategy(new TransactionManager, $provider)))->execute(
            $connection,
            (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))->dropColumn(new Identifier('obsolete')),
        );

        self::assertSame(['id', 'name'], array_column($connection->query('PRAGMA table_info(users)')->fetchAll(), 'name'));
        self::assertSame([['id' => 1, 'name' => 'Ada']], $connection->query('SELECT id, name FROM users')->fetchAll());
    }

    public function test_add_and_rename_column_operations_work_against_sqlite_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlcraft-');
        self::assertNotFalse($path);

        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connection = new PdoConnection($pdo, new SqlitePlatform, new PdoExceptionTranslator, databaseName: 'main');
            $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

            (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))->withColumn(
                new ColumnDefinition('email', new DataType('TEXT'), true, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null),
            )->execute($connection);
            (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))->renameTo(new Identifier('people'))->execute($connection);

            self::assertSame(['id', 'name', 'email'], array_column($connection->query('PRAGMA table_info(people)')->fetchAll(), 'name'));
        } finally {
            unlink($path);
        }
    }
}
