<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Connection\Result;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\Result\BufferedResult;
use SQLCraft\Contracts\Connection\ResultColumn;

final class BufferedResultTest extends TestCase
{
    public function test_buffered_result_supports_cursor_operations_and_metadata(): void
    {
        $columns = [new ResultColumn('id', 'INTEGER', 'users', 11, false)];
        $result = new BufferedResult([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ], $columns);

        self::assertFalse($result->isStreaming());
        self::assertCount(2, $result);
        self::assertSame($columns, $result->getColumns());
        self::assertSame(['id' => 1, 'name' => 'Ada'], $result->fetchAssoc());
        self::assertSame([2, 'Grace'], $result->fetchRow());
        self::assertNull($result->fetchAssoc());
    }

    public function test_buffered_result_can_seek_and_fetch_columns(): void
    {
        $result = new BufferedResult([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ]);

        $result->seek(0);
        self::assertSame([1, 2], $result->fetchColumn('id'));
        $result->seek(0);
        self::assertSame(['Ada', 'Grace'], $result->fetchColumn(1));
        $result->seek(0);
        self::assertSame([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ], $result->fetchAll());
        self::assertSame([], iterator_to_array($result));
    }

    public function test_buffered_result_rejects_invalid_seek_offsets(): void
    {
        $result = new BufferedResult([['id' => 1]]);

        $this->expectException(OutOfBoundsException::class);
        $result->seek(-1);
    }
}
