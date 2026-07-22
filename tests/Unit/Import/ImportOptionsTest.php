<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Import\ImportOptions;

final class ImportOptionsTest extends TestCase
{
    public function test_defaults_match_the_planned_safe_import_policy(): void
    {
        $options = new ImportOptions;

        self::assertTrue($options->stopOnError);
        self::assertFalse($options->wrapInTransaction);
        self::assertSame(50, $options->progressInterval);
        self::assertSame(0, $options->statementTimeoutMs);
        self::assertNull($options->maxStatements);
    }

    public function test_all_options_can_be_configured(): void
    {
        $options = new ImportOptions(
            stopOnError: false,
            wrapInTransaction: true,
            progressInterval: 10,
            statementTimeoutMs: 2500,
            maxStatements: 1000,
        );

        self::assertFalse($options->stopOnError);
        self::assertTrue($options->wrapInTransaction);
        self::assertSame(10, $options->progressInterval);
        self::assertSame(2500, $options->statementTimeoutMs);
        self::assertSame(1000, $options->maxStatements);
    }
}
