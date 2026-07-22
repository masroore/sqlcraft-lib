<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ResultColumn;

final class ResultColumnTest extends TestCase
{
    public function test_stores_column_metadata_as_an_immutable_snapshot(): void
    {
        $column = new ResultColumn('id', 'INTEGER', 'users', 8, false);

        self::assertSame('id', $column->name);
        self::assertSame('INTEGER', $column->nativeType);
        self::assertSame('users', $column->table);
        self::assertSame(8, $column->length);
        self::assertFalse($column->nullable);
        self::assertTrue((new \ReflectionClass($column))->isReadOnly());
    }
}
