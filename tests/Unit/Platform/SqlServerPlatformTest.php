<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Platform;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Connection\ResultInterface;
use SQLCraft\DTO\CheckConstraintMeta;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\Platform\SqlServerPlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

final class SqlServerPlatformTest extends TestCase
{
    public function testItDescribesSqlServerDefaultsAndCapabilities(): void
    {
        $platform = new SqlServerPlatform();

        self::assertSame('sqlserver', $platform->getName());
        self::assertNull($platform->getFlavor());
        self::assertSame('UTF-8', $platform->getDefaultCharset());
        self::assertNull($platform->getDefaultCollation());
        self::assertTrue($platform->supportsSchemas());
        self::assertTrue($platform->getCapabilitySet(new ServerVersion('16.0.0'))->has(Capability::Sequence));
        self::assertFalse($platform->getCapabilitySet(new ServerVersion('10.0.0'))->has(Capability::Sequence));
        self::assertNotContains('REGEXP', $platform->getOperators());
    }

    public function testItDetectsServerVersionFromTheConnection(): void
    {
        $result = self::createMock(ResultInterface::class);
        $result->method('fetchColumn')->willReturn(['16.0.4125.3']);
        $connection = self::createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with("SELECT CONVERT(varchar(128), SERVERPROPERTY('ProductVersion'))")
            ->willReturn($result);

        self::assertSame('16.0.4125', (string) (new SqlServerPlatform())->getServerVersion($connection));
    }

    public function testItQuotesSqlServerValuesAndPaginates(): void
    {
        $platform = new SqlServerPlatform();

        self::assertSame('[a]]b]', $platform->quoteIdentifier(new Identifier('a]b')));
        self::assertSame("'a\\\\b''c'", $platform->quoteValue("a\\b'c"));
        self::assertSame('0x00ff', $platform->quoteBinary("\x00\xff"));
        self::assertSame('SELECT * ORDER BY id OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', $platform->applyPagination('SELECT * ORDER BY id', 10, 20));
        self::assertSame('SELECT TOP 1 * FROM (SELECT * FROM users WHERE id = 1) AS [sqlcraft_single_row]', $platform->applySingleRowLimit('SELECT * FROM users', 'WHERE id = 1'));
        self::assertSame(['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', 'BETWEEN', 'NOT BETWEEN'], $platform->getOperators());

        $this->expectException(InvalidArgumentException::class);
        $platform->applyPagination('SELECT * ORDER BY id', -1, 0);
    }

    public function testItRendersSqlServerDdl(): void
    {
        $platform = new SqlServerPlatform();
        $table = new QualifiedName(new Identifier('users'), new Identifier('dbo'), new Identifier('app'));
        $column = new ColumnMeta(
            name: 'id',
            dataType: new DataType('INT'),
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

        self::assertStringContainsString('CREATE TABLE [app].[dbo].[users]', $platform->renderCreateTableStatement($table, [], [], []));
        self::assertSame('[id] INT NOT NULL IDENTITY(1,1)', $platform->renderColumnDefinition($column));
        self::assertSame('CREATE TABLE [app].[dbo].[users] ([id] INT NOT NULL IDENTITY(1,1), PRIMARY KEY ([id] ASC))', $platform->renderCreateTableStatement($table, [$platform->renderColumnDefinition($column)], [$platform->renderPrimaryKeyClause($index)], []));
        self::assertSame('DROP INDEX [users_pk] ON [app].[dbo].[users]', $platform->renderDropIndexStatement($table, new Identifier('users_pk')));
        self::assertSame('CONSTRAINT [positive_id] CHECK (id > 0)', $platform->renderCheckConstraintClause(new CheckConstraintMeta('positive_id', 'id > 0', true)));
    }

    public function testItReturnsNativeCatalogSql(): void
    {
        $platform = new SqlServerPlatform();
        $table = new QualifiedName(new Identifier('users'), new Identifier('dbo'), new Identifier('app'));

        self::assertStringContainsString('sys.databases', $platform->getDatabasesSql());
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $platform->getColumnsSql($table));
        self::assertStringContainsString('sys.indexes', $platform->getIndexesSql($table));
        self::assertStringContainsString('sys.sequences', $platform->getSequencesSql());
        self::assertStringContainsString('SHOWPLAN_ALL', $platform->getExplainSql('SELECT 1'));
    }
}
