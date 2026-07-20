<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\Platform\MariaDbPlatform;
use SQLCraft\Platform\MySQLPlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

final class MySQLPlatformTest extends TestCase
{
    public function testItDescribesMySqlDefaultsAndCapabilities(): void
    {
        $platform = new MySQLPlatform();

        self::assertSame('mysql', $platform->getName());
        self::assertNull($platform->getFlavor());
        self::assertSame('utf8mb4', $platform->getDefaultCharset());
        self::assertSame('utf8mb4_unicode_ci', $platform->getDefaultCollation());
        self::assertFalse($platform->supportsSchemas());
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('8.0.15'))->has(Capability::GeneratedColumns));
        self::assertFalse($platform->getCapabilitySet(new ServerVersion('8.0.15'))->has(Capability::CheckConstraints));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('8.0.16'))->has(Capability::CheckConstraints));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('8.0.16'))->has(Capability::DescendingIndexes));
    }

    public function testMariaDbSpecializesFlavorAndVersionGates(): void
    {
        $platform = new MariaDbPlatform();

        self::assertSame('mariadb', $platform->getName());
        self::assertSame('maria', $platform->getFlavor());
        self::assertFalse($platform->getCapabilitySet(new ServerVersion('10.2.0'))->has(Capability::CheckConstraints));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('10.2.1'))->has(Capability::CheckConstraints));
        self::assertFalse($platform->getCapabilitySet(new ServerVersion('10.2.9'))->has(Capability::Sequence));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('10.3.0'))->has(Capability::Sequence));
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('5.1.0'))->has(Capability::DescendingIndexes));
        self::assertStringContainsString("ENGINE = 'SEQUENCE'", $platform->getSequencesSql());
    }

    public function testItDetectsServerVersionFromTheConnection(): void
    {
        $result = self::createMock(ResultInterface::class);
        $result->method('fetchColumn')->willReturn(['8.0.36']);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('query')->with('SELECT VERSION()')->willReturn($result);

        self::assertSame('8.0.36', (string) (new MySQLPlatform())->getServerVersion($connection));
    }

    public function testItQuotesValuesIdentifiersAndPaginates(): void
    {
        $platform = new MySQLPlatform();

        self::assertSame('`a``b`', $platform->quoteIdentifier(new Identifier('a`b')));
        self::assertSame("'a\\\\b''c'", $platform->quoteValue("a\\b'c"));
        self::assertSame("X'00ff'", $platform->quoteBinary("\x00\xff"));
        self::assertSame('SELECT * LIMIT 10 OFFSET 20', $platform->applyPagination('SELECT *', 10, 20));
        self::assertSame('SELECT * WHERE id = 1 LIMIT 1', $platform->applySingleRowLimit('SELECT *', 'WHERE id = 1'));

        $this->expectException(InvalidArgumentException::class);
        $platform->applyPagination('SELECT *', -1, 0);
    }

    public function testItRendersMySqlDdl(): void
    {
        $platform = new MySQLPlatform();
        $table = new QualifiedName(new Identifier('users'), catalog: new Identifier('app'));
        $column = new ColumnMeta(
            name: 'id',
            dataType: new DataType('INT', unsigned: true),
            nullable: false,
            autoIncrement: true,
            primary: false,
            generated: false,
            default: DefaultValue::nullValue(),
            collation: null,
            comment: 'identifier',
            onUpdate: null,
            privileges: [],
            origName: null,
            defaultConstraintName: null,
        );
        $index = new IndexMeta(
            name: 'users_pk',
            type: IndexType::PRIMARY,
            columns: [new IndexColumnMeta('id', false, null, null)],
            unique: true,
            comment: null,
            algorithm: null,
            filterExpression: null,
        );
        $foreignKey = new ForeignKeyMeta(
            constraintName: 'users_team_fk',
            targetDatabase: 'app',
            targetSchema: null,
            targetTable: 'teams',
            sourceColumns: ['team_id'],
            targetColumns: ['id'],
            onDelete: ForeignKeyAction::CASCADE,
            onUpdate: ForeignKeyAction::RESTRICT,
            definition: null,
        );

        self::assertSame(
            'CREATE TABLE `app`.`users` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'identifier\', PRIMARY KEY (`id` ASC)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $platform->renderCreateTableStatement(
                $table,
                [$platform->renderColumnDefinition($column)],
                [$platform->renderPrimaryKeyClause($index)],
                ['engine' => 'InnoDB', 'charset' => 'utf8mb4'],
            ),
        );
        self::assertStringContainsString('REFERENCES `app`.`teams` (`id`)', $platform->renderForeignKeyClause($foreignKey));
        self::assertSame('ALTER TABLE `app`.`users` DROP COLUMN `id`', $platform->renderAlterTableDropColumn($table, new Identifier('id')));
        self::assertSame('DROP INDEX `users_pk` ON `app`.`users`', $platform->renderDropIndexStatement($table, new Identifier('users_pk')));
        self::assertSame('CONSTRAINT `positive_id` CHECK (id > 0)', $platform->renderCheckConstraintClause(new CheckConstraintMeta('positive_id', 'id > 0', true)));
    }

    public function testItReturnsMySqlIntrospectionSqlAndRejectsSequences(): void
    {
        $platform = new MySQLPlatform();
        $table = new QualifiedName(new Identifier('users'), catalog: new Identifier('app'));

        self::assertSame('SHOW DATABASES', $platform->getDatabasesSql());
        self::assertStringContainsString("TABLE_SCHEMA = 'app'", $platform->getColumnsSql($table));
        self::assertSame('SHOW INDEX FROM `app`.`users`', $platform->getIndexesSql($table));
        self::assertSame('SHOW PROCESSLIST', $platform->getProcesslistSql());

        try {
            $platform->getSequencesSql();
            self::fail('Expected sequence capability exception.');
        } catch (CapabilityNotSupportedException $exception) {
            self::assertSame(Capability::Sequence, $exception->capability);
            self::assertSame('mysql', $exception->platform);
        }
    }
}
