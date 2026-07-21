<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\DDL\Definition\CheckConstraintDefinition;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\DDL\Definition\ForeignKeyDefinition;
use SQLCraft\DDL\Definition\IndexColumnDefinition;
use SQLCraft\DDL\Definition\IndexDefinition;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;

final class DefinitionTest extends TestCase
{
    public function testDefinitionsExposeImmutableTypedMetadata(): void
    {
        $column = new ColumnDefinition(
            'id',
            new DataType('INTEGER'),
            false,
            true,
            true,
            false,
            DefaultValue::nullValue(),
            null,
            'identifier',
            null,
            [1],
            null,
            null,
        );
        $indexColumn = new IndexColumnDefinition('id', true, 8, null);
        $index = new IndexDefinition('primary', IndexType::PRIMARY, [$indexColumn], true, null, null, null);
        $foreignKey = new ForeignKeyDefinition(
            'fk_parent',
            null,
            null,
            'parents',
            ['parent_id'],
            ['id'],
            ForeignKeyAction::CASCADE,
            ForeignKeyAction::RESTRICT,
            null,
            false,
        );
        $check = new CheckConstraintDefinition('positive', 'id > 0', true);

        self::assertSame('id', $column->getName());
        self::assertTrue($column->isAutoIncrement());
        self::assertSame([$indexColumn], $index->getColumns());
        self::assertSame('parents', $foreignKey->getTargetTable());
        self::assertSame(['id'], $foreignKey->getTargetColumns());
        self::assertSame('id > 0', $check->getExpression());
    }

    public function testPlatformAdaptsProjectionToExistingDialectRendering(): void
    {
        $platform = new SqlitePlatform();
        $column = new ColumnDefinition(
            'id',
            new DataType('INTEGER'),
            false,
            true,
            true,
            false,
            DefaultValue::nullValue(),
            null,
            null,
            null,
            [],
            null,
            null,
        );
        $index = new IndexDefinition(
            'primary',
            IndexType::PRIMARY,
            [new IndexColumnDefinition('id', false, null, null)],
            true,
            null,
            null,
            null,
        );

        self::assertSame('"id" INTEGER PRIMARY KEY NOT NULL AUTOINCREMENT', $platform->renderDdlColumnDefinition($column));
        self::assertSame('PRIMARY KEY ("id" ASC)', $platform->renderDdlPrimaryKeyClause($index));
        self::assertSame('CREATE UNIQUE INDEX "primary" ON "users" ("id" ASC)', $platform->renderDdlCreateIndexStatement(
            new \SQLCraft\ValueObjects\QualifiedName(new \SQLCraft\ValueObjects\Identifier('users')),
            $index,
        ));
    }
}
