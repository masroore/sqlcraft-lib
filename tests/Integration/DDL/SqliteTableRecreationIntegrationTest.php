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
use SQLCraft\DDL\AlterTableBuilder;
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
    public function testDropColumnRecreatesTableAndPreservesRows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection = new PdoConnection($pdo, new SqlitePlatform(), new PdoExceptionTranslator(), databaseName: 'main');
        $connection->execute('PRAGMA foreign_keys = ON');
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, obsolete TEXT, name TEXT NOT NULL)');
        $connection->execute('INSERT INTO users (obsolete, name) VALUES (?, ?)', ['remove', 'Ada']);

        $definition = new TableRecreationDefinition([
            new ColumnDefinition('id', new DataType('INTEGER'), true, false, true, false, DefaultValue::nullValue(), null, null, null, [], null, null),
            new ColumnDefinition('obsolete', new DataType('TEXT'), true, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null),
            new ColumnDefinition('name', new DataType('TEXT'), false, false, false, false, DefaultValue::nullValue(), null, null, null, [], null, null),
        ]);
        $provider = new class ($definition) implements TableRecreationMetadataProviderInterface {
            public function __construct(private readonly TableRecreationDefinition $definition)
            {
            }

            #[\Override]
            public function getDefinition(ConnectionInterface $connection, QualifiedName $table): TableRecreationDefinition
            {
                return $this->definition;
            }
        };

        (new TableRecreationStrategy(new TransactionManager(), $provider))->execute(
            $connection,
            (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))->dropColumn(new Identifier('obsolete')),
        );

        self::assertSame(['id', 'name'], array_column($connection->query('PRAGMA table_info(users)')->fetchAll(), 'name'));
        self::assertSame([['id' => 1, 'name' => 'Ada']], $connection->query('SELECT id, name FROM users')->fetchAll());
    }
}
