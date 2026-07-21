<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\CreateIndexBuilder;
use SQLCraft\DDL\CreateTableBuilder;
use SQLCraft\DDL\DropIndexBuilder;
use SQLCraft\DDL\DropTableBuilder;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\DDL\Definition\IndexDefinition;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\QualifiedName;

final class TableAndIndexBuilderTest extends TestCase
{
    public function testCreateTableDelegatesImmutableIntentToDialect(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $column = new ColumnDefinition('id', new DataType('INTEGER'), false, false, true, false, DefaultValue::nullValue(), null, null, null, [], null, null);
        $index = new IndexDefinition('PRIMARY', IndexType::PRIMARY, [], true, null, null, null);
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderDdlColumnDefinition')->with($column)->willReturn('"id" INTEGER');
        $dialect->expects(self::once())->method('renderDdlPrimaryKeyClause')->with($index)->willReturn('PRIMARY KEY ("id")');
        $dialect->expects(self::once())->method('renderCreateTableStatement')->with($table, ['"id" INTEGER'], ['PRIMARY KEY ("id")'], self::arrayHasKey('if_not_exists'))->willReturn('CREATE TABLE');

        $builder = (new CreateTableBuilder($table))->withColumn($column)->withIndex($index);

        self::assertSame(['CREATE TABLE'], $builder->toSql($dialect));
        self::assertSame([], (new CreateTableBuilder($table))->columns);
    }

    public function testDropTableDelegatesFlags(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderDropTableStatement')->with($table, true, true)->willReturn('DROP TABLE');

        self::assertSame(['DROP TABLE'], (new DropTableBuilder($table, true, true))->toSql($dialect));
    }

    public function testIndexBuildersDelegateToDialect(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $index = new IndexDefinition('email_idx', IndexType::UNIQUE, [], true, null, null, null);
        $dialect = self::createMock(DdlDialectInterface::class);
        $dialect->expects(self::once())->method('renderDdlCreateIndexStatement')->with($table, $index)->willReturn('CREATE INDEX');
        $dialect->expects(self::once())->method('renderDropIndexStatement')->with($table, self::isInstanceOf(Identifier::class))->willReturn('DROP INDEX');

        self::assertSame(['CREATE INDEX'], (new CreateIndexBuilder($table, $index))->toSql($dialect));
        self::assertSame(['DROP INDEX'], (new DropIndexBuilder($table, new Identifier('email_idx')))->toSql($dialect));
    }
}
