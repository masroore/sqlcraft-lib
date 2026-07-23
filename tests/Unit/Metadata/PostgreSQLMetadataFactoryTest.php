<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SQLCraft\Metadata\PostgreSQLMetadataFactory;
use SQLCraft\ValueObjects\DefaultValueKind;
use SQLCraft\ValueObjects\ForeignKeyAction;
use SQLCraft\ValueObjects\IndexType;

final class PostgreSQLMetadataFactoryTest extends TestCase
{
    public function test_hydrates_postgre_sql_column_and_sequence_default(): void
    {
        $column = (new PostgreSQLMetadataFactory())->createColumnMeta([
            'column_name' => 'id',
            'udt_name' => 'int4',
            'is_nullable' => 'NO',
            'column_default' => "nextval('users_id_seq'::regclass)",
            'is_identity' => 'YES',
        ]);

        self::assertSame('INT4', $column->dataType->name);
        self::assertFalse($column->nullable);
        self::assertSame(DefaultValueKind::SEQUENCE_NEXT, $column->default->kind);
        self::assertSame("nextval('users_id_seq'::regclass)", $column->default->value);
    }

    public function test_hydrates_postgre_sql_index_and_foreign_key(): void
    {
        $factory = new PostgreSQLMetadataFactory();
        $index = $factory->createIndexMeta([
            'index_name' => 'users_search',
            'index_type' => 'GIN',
            'indisunique' => true,
            'column_name' => 'search_vector',
        ]);
        $foreignKey = $factory->createForeignKeyMeta([
            'constraint_name' => 'fk_users_team',
            'source_columns' => 'team_id',
            'target_columns' => 'id',
            'target_table' => 'teams',
            'on_delete' => 'SET NULL',
            'on_update' => 'NO ACTION',
            'deferrable' => true,
        ]);

        self::assertSame(IndexType::GIN, $index->type);
        self::assertTrue($index->unique);
        self::assertTrue($foreignKey->deferrable);
        self::assertSame(ForeignKeyAction::SET_NULL, $foreignKey->onDelete);
    }

    public function test_hydrates_postgre_sql_routine_and_table_status(): void
    {
        $factory = new PostgreSQLMetadataFactory();
        $routine = $factory->createRoutineMeta([
            'routine_name' => 'refresh_users',
            'routine_type' => 'PROCEDURE',
            'routine_definition' => 'BEGIN ATOMIC END',
            'routine_schema' => 'public',
        ]);
        $status = $factory->createTableStatus([
            'relname' => 'users',
            'reltuples' => 42.0,
            'relkind' => 'r',
            'table_schema' => 'public',
        ]);

        self::assertSame('PROCEDURE', $routine->type);
        self::assertSame('BEGIN ATOMIC END', $routine->body);
        self::assertSame(42, $status->rows);
        self::assertSame('public', $status->schema);
    }
}
