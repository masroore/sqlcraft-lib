<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Metadata\SqliteMetadataFactory;
use SQLCraft\ValueObjects\DefaultValueKind;
use SQLCraft\ValueObjects\TriggerEvent;

final class SqliteMetadataFactoryTest extends TestCase
{
    public function test_hydrates_sqlite_pragma_column_row(): void
    {
        $column = (new SqliteMetadataFactory)->createColumnMeta([
            'name' => 'created_at',
            'type' => 'TEXT',
            'notnull' => 1,
            'dflt_value' => 'CURRENT_TIMESTAMP',
            'pk' => 0,
        ]);

        self::assertSame('created_at', $column->name);
        self::assertSame('TEXT', $column->dataType->name);
        self::assertFalse($column->nullable);
        self::assertSame(DefaultValueKind::EXPRESSION, $column->default->kind);
    }

    public function test_hydrates_sqlite_table_index_and_trigger_rows(): void
    {
        $factory = new SqliteMetadataFactory;
        $status = $factory->createTableStatus([
            'name' => 'users',
            'type' => 'table',
            'schema' => 'main',
        ]);
        $index = $factory->createIndexMeta([
            'name' => 'users_email',
            'column_name' => 'email',
            'unique' => 1,
            'type' => 'UNIQUE',
        ]);
        $trigger = $factory->createTriggerMeta([
            'name' => 'users_inserted',
            'timing' => 'BEFORE',
            'event' => 'INSERT',
            'sql' => 'BEGIN SELECT 1; END',
            'table_name' => 'users',
        ]);

        self::assertFalse($status->isView);
        self::assertTrue($index->unique);
        self::assertSame(TriggerEvent::INSERT, $trigger->event);
        self::assertSame('users', $trigger->table);
    }

    public function test_missing_required_metadata_field_is_rejected(): void
    {
        self::expectException(\InvalidArgumentException::class);

        (new SqliteMetadataFactory)->createTableStatus([]);
    }
}
