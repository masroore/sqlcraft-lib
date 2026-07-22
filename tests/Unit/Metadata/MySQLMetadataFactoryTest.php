<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Metadata\MySQLMetadataFactory;
use SQLCraft\ValueObjects\DefaultValueKind;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final class MySQLMetadataFactoryTest extends TestCase
{
    public function test_hydrates_my_sql_column_metadata(): void
    {
        $column = (new MySQLMetadataFactory)->createColumnMeta([
            'COLUMN_NAME' => 'id',
            'DATA_TYPE' => 'int',
            'COLUMN_TYPE' => 'int unsigned',
            'IS_NULLABLE' => 'NO',
            'EXTRA' => 'auto_increment',
            'COLUMN_KEY' => 'PRI',
            'COLUMN_DEFAULT' => null,
            'COLLATION_NAME' => null,
        ]);

        self::assertSame('id', $column->name);
        self::assertSame('INT', $column->dataType->name);
        self::assertTrue($column->dataType->unsigned);
        self::assertFalse($column->nullable);
        self::assertTrue($column->autoIncrement);
        self::assertTrue($column->primary);
        self::assertSame(DefaultValueKind::NULL_VALUE, $column->default->kind);
    }

    public function test_hydrates_my_sql_index_foreign_key_trigger_and_routine_rows(): void
    {
        $factory = new MySQLMetadataFactory;

        $index = $factory->createIndexMeta([
            'Key_name' => 'users_email',
            'Column_name' => 'email',
            'Non_unique' => 0,
            'Index_type' => 'BTREE',
        ]);
        $foreignKey = $factory->createForeignKeyMeta([
            'CONSTRAINT_NAME' => 'fk_users_team',
            'COLUMN_NAME' => 'team_id',
            'REFERENCED_TABLE_NAME' => 'teams',
            'REFERENCED_COLUMN_NAME' => 'id',
            'DELETE_RULE' => 'CASCADE',
            'UPDATE_RULE' => 'RESTRICT',
        ]);
        $trigger = $factory->createTriggerMeta([
            'name' => 'users_updated',
            'Timing' => 'AFTER',
            'Event' => 'UPDATE',
            'body' => 'SET NEW.updated_at = CURRENT_TIMESTAMP',
            'Table' => 'users',
        ]);
        $routine = $factory->createRoutineMeta([
            'ROUTINE_NAME' => 'active_users',
            'ROUTINE_TYPE' => 'FUNCTION',
            'PARAMS' => 'minimum_age:INT:IN',
            'RETURN_TYPE' => 'INT',
            'ROUTINE_DEFINITION' => 'RETURN 1',
            'IS_DETERMINISTIC' => 'YES',
            'SQL_DATA_ACCESS' => 'READS SQL DATA',
        ]);

        self::assertSame(IndexType::INDEX, $index->type);
        self::assertSame('email', $index->columns[0]->columnName);
        self::assertSame(ForeignKeyAction::CASCADE, $foreignKey->onDelete);
        self::assertSame(ForeignKeyAction::RESTRICT, $foreignKey->onUpdate);
        self::assertSame(TriggerTiming::AFTER, $trigger->timing);
        self::assertSame(TriggerEvent::UPDATE, $trigger->event);
        self::assertSame('minimum_age', $routine->params[0]->name);
        self::assertTrue($routine->deterministic);
    }

    public function test_hydrates_my_sql_table_status(): void
    {
        $status = (new MySQLMetadataFactory)->createTableStatus([
            'Name' => 'users',
            'Engine' => 'InnoDB',
            'Rows' => '12',
            'Comment' => 'Application users',
            'TABLE_SCHEMA' => 'app',
        ]);

        self::assertSame('users', $status->name);
        self::assertSame('InnoDB', $status->engine);
        self::assertSame(12, $status->rows);
        self::assertSame('app', $status->schema);
    }
}
