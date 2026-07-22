<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DDL;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\CapabilityNotSupportedException;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\DDL\AlterTableBuilder;
use SQLCraft\DDL\Definition\ColumnDefinition;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final class AlterTableBuilderTest extends TestCase
{
    public function test_builder_accumulates_immutable_operations_and_delegates_rendering(): void
    {
        $table = new QualifiedName(new Identifier('users'));
        $column = $this->column('email', new DataType('TEXT'));
        $dialect = self::createMock(DdlDialectInterface::class);
        $builder = (new AlterTableBuilder($table))
            ->withColumn($column)
            ->dropColumn(new Identifier('obsolete'))
            ->renameTo(new Identifier('accounts'));

        $dialect->expects(self::once())
            ->method('renderDdlAlterTable')
            ->with($builder)
            ->willReturn(['ALTER TABLE']);

        self::assertSame(['ALTER TABLE'], $builder->toSql($dialect));
        self::assertSame([], (new AlterTableBuilder($table))->getAddColumns());
        self::assertSame($table, $builder->getTable());
        self::assertCount(1, $builder->getAddColumns());
        self::assertSame('obsolete', $builder->getDropColumns()[0]->name);
        self::assertSame('accounts', $builder->getRename()?->name);
    }

    public function test_abstract_dialect_renders_common_alter_operations(): void
    {
        $platform = new SqlitePlatform;
        $table = new QualifiedName(new Identifier('users'));
        $builder = (new AlterTableBuilder($table))
            ->withColumn($this->column('email', new DataType('TEXT')))
            ->dropColumn(new Identifier('obsolete'))
            ->renameTo(new Identifier('accounts'));

        self::assertSame([
            'ALTER TABLE "users" ADD COLUMN "email" TEXT',
            'ALTER TABLE "users" DROP COLUMN "obsolete"',
            'ALTER TABLE "users" RENAME TO "accounts"',
        ], $platform->renderDdlAlterTable($builder));
    }

    public function test_column_position_requires_platform_specific_capability(): void
    {
        $this->expectException(CapabilityNotSupportedException::class);

        (new SqlitePlatform)->renderDdlAlterTable(
            (new AlterTableBuilder(new QualifiedName(new Identifier('users'))))
                ->withColumn($this->column('email', new DataType('TEXT')), new Identifier('id')),
        );
    }

    private function column(string $name, DataType $type): ColumnDefinition
    {
        return new ColumnDefinition(
            $name,
            $type,
            true,
            false,
            false,
            false,
            DefaultValue::nullValue(),
            null,
            null,
            null,
            [],
            null,
            null,
        );
    }
}
