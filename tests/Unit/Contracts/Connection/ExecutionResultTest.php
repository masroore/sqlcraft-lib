<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Connection;

use PHPUnit\Framework\TestCase;
use SQLCraft\DTO\ExecutionResult;

final class ExecutionResultTest extends TestCase
{
    public function testStoresExecutionMetadataAsAnImmutableSnapshot(): void
    {
        $result = new ExecutionResult(3, 42, 1.25, 'INSERT INTO users');

        self::assertSame(3, $result->affectedRows);
        self::assertSame(42, $result->lastInsertId);
        self::assertSame(1.25, $result->elapsedMs);
        self::assertSame('INSERT INTO users', $result->sql);
        self::assertTrue((new \ReflectionClass($result))->isReadOnly());
    }
}
