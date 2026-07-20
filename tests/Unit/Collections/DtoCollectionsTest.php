<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Collections;

use PHPUnit\Framework\TestCase;
use SQLCraft\Collections\ColumnCollection;
use SQLCraft\Collections\DatabaseCollection;
use SQLCraft\Collections\ForeignKeyCollection;
use SQLCraft\Collections\IndexCollection;
use SQLCraft\Collections\PrivilegeCollection;
use SQLCraft\Collections\RoutineCollection;
use SQLCraft\Collections\TableCollection;
use SQLCraft\Collections\TriggerCollection;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\DatabaseMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\DTO\TableStatus;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\ValueObjects\DataType;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\Privilege;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final class DtoCollectionsTest extends TestCase
{
    public function testMetadataCollectionsExposeTheirDeclaredItemTypes(): void
    {
        $column = new ColumnMeta(
            name: 'id',
            dataType: new DataType('INT'),
            nullable: false,
            autoIncrement: true,
            primary: true,
            generated: false,
            default: DefaultValue::nullValue(),
            collation: null,
            comment: null,
            onUpdate: null,
            privileges: [],
            origName: null,
            defaultConstraintName: null,
        );
        $index = new IndexMeta(
            name: 'users_pkey',
            type: IndexType::PRIMARY,
            columns: [new IndexColumnMeta('id', false, null, null)],
            unique: true,
            comment: null,
            algorithm: null,
            filterExpression: null,
        );
        $foreignKey = new ForeignKeyMeta(
            constraintName: 'orders_user_id_foreign',
            targetDatabase: null,
            targetSchema: 'public',
            targetTable: 'users',
            sourceColumns: ['user_id'],
            targetColumns: ['id'],
            onDelete: ForeignKeyAction::CASCADE,
            onUpdate: ForeignKeyAction::RESTRICT,
            definition: null,
        );
        $routine = new RoutineMeta(
            name: 'reset_users',
            type: 'PROCEDURE',
            params: [],
            returnType: null,
            body: 'DELETE FROM users',
            language: 'SQL',
            comment: null,
            definer: 'app',
            deterministic: false,
            sqlDataAccess: 'MODIFIES SQL DATA',
        );
        $trigger = new TriggerMeta(
            name: 'users_audit',
            timing: TriggerTiming::AFTER,
            event: TriggerEvent::INSERT,
            body: 'INSERT INTO audit_log VALUES (NEW.id)',
            definer: null,
            table: 'users',
        );

        $columns = new ColumnCollection(['id' => $column]);
        $indexes = new IndexCollection(['users_pkey' => $index]);
        $foreignKeys = new ForeignKeyCollection(['orders_user_id_foreign' => $foreignKey]);
        $tables = new TableCollection(['users' => new TableStatus('users')]);
        $routines = new RoutineCollection(['reset_users' => $routine]);
        $triggers = new TriggerCollection(['users_audit' => $trigger]);
        $privileges = new PrivilegeCollection(['select' => new Privilege('SELECT')]);
        $databases = new DatabaseCollection(['shop' => new DatabaseMeta('shop', 'utf8mb4', null)]);

        self::assertSame($column, $columns->get('id'));
        self::assertSame($index, $indexes->get('users_pkey'));
        self::assertSame($foreignKey, $foreignKeys->get('orders_user_id_foreign'));
        self::assertSame('users', $tables->get('users')->name);
        self::assertSame($routine, $routines->get('reset_users'));
        self::assertSame($trigger, $triggers->get('users_audit'));
        self::assertSame('SELECT', $privileges->get('select')->name);
        self::assertSame('shop', $databases->get('shop')->name);
    }

    public function testFilteringPreservesConcreteCollectionAndKeys(): void
    {
        $tables = new TableCollection([
            'users' => new TableStatus('users'),
            'audit_log' => new TableStatus('audit_log'),
        ]);

        $filtered = $tables->filter(
            static fn (TableStatus $table): bool => $table->name === 'audit_log',
        );

        self::assertSame(TableCollection::class, get_class($filtered));
        self::assertCount(2, $tables);
        self::assertCount(1, $filtered);
        self::assertSame('audit_log', $filtered->get('audit_log')->name);
        self::assertTrue(isset($filtered['audit_log']));
        self::assertFalse(isset($filtered['users']));
    }

    public function testMappingProducesAListWithoutChangingTheCollection(): void
    {
        $databases = new DatabaseCollection([
            'shop' => new DatabaseMeta('shop', null, null),
            'analytics' => new DatabaseMeta('analytics', null, null),
        ]);

        self::assertSame(
            ['SHOP', 'ANALYTICS'],
            $databases->map(static fn (DatabaseMeta $database): string => strtoupper($database->name)),
        );
        self::assertCount(2, $databases);
    }
}
