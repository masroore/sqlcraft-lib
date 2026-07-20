<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Import\ImportError;
use SQLCraft\Import\ImportResult;

final class ImportResultTest extends TestCase
{
    public function testResultStoresExecutionSummaryAndTypedErrors(): void
    {
        $error = new ImportError(3, 'BAD SQL', 'syntax error', 42601);
        $result = new ImportResult(2, 1, [$error], 12.5);

        self::assertSame(2, $result->statementsExecuted);
        self::assertSame(1, $result->statementsSkipped);
        self::assertSame([$error], $result->errors);
        self::assertSame(12.5, $result->elapsedMs);
    }

    public function testImportErrorStoresStatementFailureContext(): void
    {
        $error = new ImportError(7, 'DROP TABLE missing', 'not found', 42);

        self::assertSame(7, $error->statementIndex);
        self::assertSame('DROP TABLE missing', $error->sql);
        self::assertSame('not found', $error->errorMessage);
        self::assertSame(42, $error->errorCode);
    }
}
