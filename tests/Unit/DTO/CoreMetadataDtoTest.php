<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\ValueObjects\Collation;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ForeignKeyAction;

final class CoreMetadataDtoTest extends TestCase
{
    public function testColumnMetaStoresItsSchemaSnapshot(): void
    {
        $column = new ColumnMeta(
            name: 'created_at',
            dataType: new DataType('TIMESTAMP'),
            nullable: false,
            autoIncrement: false,
            primary: false,
            generated: false,
            default: DefaultValue::expression('CURRENT_TIMESTAMP'),
            collation: new Collation('utf8mb4_unicode_ci'),
            comment: 'Creation time',
            onUpdate: 'CURRENT_TIMESTAMP',
            privileges: [1, 4],
            origName: 'created',
            defaultConstraintName: null,
        );

        self::assertSame('created_at', $column->name);
        self::assertSame('TIMESTAMP', $column->dataType->name);
        self::assertFalse($column->nullable);
        self::assertFalse($column->autoIncrement);
        self::assertFalse($column->primary);
        self::assertFalse($column->generated);
        self::assertSame('CURRENT_TIMESTAMP', $column->default->value);
        self::assertSame('utf8mb4_unicode_ci', $column->collation?->name);
        self::assertSame('Creation time', $column->comment);
        self::assertSame('CURRENT_TIMESTAMP', $column->onUpdate);
        self::assertSame([1, 4], $column->privileges);
        self::assertSame('created', $column->origName);
        self::assertNull($column->defaultConstraintName);
    }

    public function testTableStatusProvidesPortableDefaultsForMissingMetadata(): void
    {
        $table = new TableStatus('users');

        self::assertSame('users', $table->name);
        self::assertFalse($table->isView);
        self::assertNull($table->engine);
        self::assertNull($table->rows);
        self::assertFalse($table->partitioned);
        self::assertNull($table->schema);
    }

    public function testTableStatusStoresEngineSpecificMetadata(): void
    {
        $table = new TableStatus(
            name: 'orders',
            isView: false,
            engine: 'InnoDB',
            comment: 'Orders',
            rows: 42,
            collation: 'utf8mb4_unicode_ci',
            autoIncrement: 43,
            dataLength: 4096,
            indexLength: 2048,
            dataFree: 0,
            createOptions: 'partitioned',
            partitioned: true,
            schema: 'public',
        );

        self::assertSame('InnoDB', $table->engine);
        self::assertSame(42, $table->rows);
        self::assertSame(43, $table->autoIncrement);
        self::assertSame(4096, $table->dataLength);
        self::assertSame(2048, $table->indexLength);
        self::assertSame('partitioned', $table->createOptions);
        self::assertTrue($table->partitioned);
        self::assertSame('public', $table->schema);
    }

    public function testForeignKeyMetaIsNotDeferrableByDefault(): void
    {
        $foreignKey = new ForeignKeyMeta(
            constraintName: 'orders_user_id_foreign',
            targetDatabase: null,
            targetSchema: null,
            targetTable: 'users',
            sourceColumns: ['user_id'],
            targetColumns: ['id'],
            onDelete: ForeignKeyAction::NO_ACTION,
            onUpdate: ForeignKeyAction::NO_ACTION,
            definition: null,
        );

        self::assertFalse($foreignKey->deferrable);
    }

    public function testForeignKeyMetaStoresOrderedColumnMappingsAndActions(): void
    {
        $foreignKey = new ForeignKeyMeta(
            constraintName: 'orders_user_id_foreign',
            targetDatabase: null,
            targetSchema: 'public',
            targetTable: 'users',
            sourceColumns: ['user_id'],
            targetColumns: ['id'],
            onDelete: ForeignKeyAction::CASCADE,
            onUpdate: ForeignKeyAction::RESTRICT,
            definition: 'FOREIGN KEY (user_id) REFERENCES users (id)',
            deferrable: true,
        );

        self::assertSame('orders_user_id_foreign', $foreignKey->constraintName);
        self::assertNull($foreignKey->targetDatabase);
        self::assertSame('public', $foreignKey->targetSchema);
        self::assertSame('users', $foreignKey->targetTable);
        self::assertSame(['user_id'], $foreignKey->sourceColumns);
        self::assertSame(['id'], $foreignKey->targetColumns);
        self::assertSame(ForeignKeyAction::CASCADE, $foreignKey->onDelete);
        self::assertSame(ForeignKeyAction::RESTRICT, $foreignKey->onUpdate);
        self::assertTrue($foreignKey->deferrable);
    }
}
