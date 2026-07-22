<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\IndexColumnMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\DTO\TriggerMeta;
use SQLCraft\ValueObjects\IndexType;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final class IndexAndTriggerDtoTest extends TestCase
{
    public function test_index_meta_stores_ordered_column_metadata(): void
    {
        $index = new IndexMeta(
            name: 'users_email_unique',
            type: IndexType::UNIQUE,
            columns: [
                new IndexColumnMeta('email', false, null, null),
            ],
            unique: true,
            comment: 'User email lookup',
            algorithm: 'BTREE',
            filterExpression: null,
        );

        self::assertSame('users_email_unique', $index->name);
        self::assertSame(IndexType::UNIQUE, $index->type);
        self::assertCount(1, $index->columns);
        self::assertSame('email', $index->columns[0]->columnName);
        self::assertFalse($index->columns[0]->descending);
        self::assertNull($index->columns[0]->length);
        self::assertNull($index->columns[0]->expression);
        self::assertTrue($index->unique);
        self::assertSame('User email lookup', $index->comment);
        self::assertSame('BTREE', $index->algorithm);
        self::assertNull($index->filterExpression);
    }

    public function test_index_column_meta_supports_prefix_and_expression_columns(): void
    {
        $column = new IndexColumnMeta(
            columnName: 'name',
            descending: true,
            length: 20,
            expression: 'lower(name)',
        );

        self::assertSame('name', $column->columnName);
        self::assertTrue($column->descending);
        self::assertSame(20, $column->length);
        self::assertSame('lower(name)', $column->expression);
    }

    public function test_trigger_meta_stores_timing_event_and_body(): void
    {
        $trigger = new TriggerMeta(
            name: 'users_updated_at',
            timing: TriggerTiming::BEFORE,
            event: TriggerEvent::UPDATE,
            body: 'SET NEW.updated_at = CURRENT_TIMESTAMP',
            definer: 'app@localhost',
            table: 'users',
        );

        self::assertSame('users_updated_at', $trigger->name);
        self::assertSame(TriggerTiming::BEFORE, $trigger->timing);
        self::assertSame(TriggerEvent::UPDATE, $trigger->event);
        self::assertSame('SET NEW.updated_at = CURRENT_TIMESTAMP', $trigger->body);
        self::assertSame('app@localhost', $trigger->definer);
        self::assertSame('users', $trigger->table);
    }
}
