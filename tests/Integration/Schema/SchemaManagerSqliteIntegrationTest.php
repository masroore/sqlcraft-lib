<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Integration\Schema;

use PDO;
use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Connection\PdoConnection;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\Schema\SchemaManagerFactory;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class SchemaManagerSqliteIntegrationTest extends TestCase
{
    public function test_describe_table_hydrates_columns_indexes_foreign_keys_triggers_and_status(): void
    {
        $connection = $this->connection();
        $connection->execute('PRAGMA foreign_keys = ON');
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
        $connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id))');
        $connection->execute('CREATE INDEX orders_user_id_idx ON orders(user_id)');
        $connection->execute('CREATE TRIGGER users_insert AFTER INSERT ON users BEGIN SELECT NEW.id; END');

        $manager = SchemaManagerFactory::forConnection($connection);
        $table = new QualifiedName(new Identifier('orders'));
        $structure = $manager->describeTable($connection, $table);

        self::assertSame('orders', $structure->status->name);
        self::assertSame('user_id', $structure->columns->get('user_id')->name);
        self::assertTrue($structure->indexes->get('orders_user_id_idx')->unique === false);
        self::assertNotEmpty($structure->foreignKeys);
        self::assertCount(0, $structure->triggers);
    }

    public function test_batch_columns_use_one_cross_table_query_and_return_typed_collections(): void
    {
        $connection = $this->connection();
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');

        $columns = SchemaManagerFactory::forConnection($connection)->getAllColumns($connection, 'main');

        self::assertSame(['id', 'email'], array_keys(iterator_to_array($columns['users'])));
        self::assertSame(['id', 'user_id'], array_keys(iterator_to_array($columns['orders'])));
    }

    public function test_unsupported_sequence_inspection_is_explicit(): void
    {
        $connection = $this->connection();

        $this->expectException(CapabilityNotSupportedException::class);
        SchemaManagerFactory::forConnection($connection)->getSequences($connection);
    }

    private function connection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(
            pdo: $pdo,
            platform: new SqlitePlatform,
            translator: new PdoExceptionTranslator,
            databaseName: 'main',
        );
    }
}
