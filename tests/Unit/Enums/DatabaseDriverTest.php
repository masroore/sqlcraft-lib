<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use SQLCraft\Enums\DatabaseDriver;

final class DatabaseDriverTest extends TestCase
{
    public function testAllCasesHaveExpectedBackingValues(): void
    {
        self::assertSame('mysql', DatabaseDriver::MySQL->value);
        self::assertSame('mariadb', DatabaseDriver::MariaDB->value);
        self::assertSame('pgsql', DatabaseDriver::PostgreSQL->value);
        self::assertSame('sqlite', DatabaseDriver::SQLite->value);
        self::assertSame('sqlserver', DatabaseDriver::SqlServer->value);
    }

    public function testFromProducesCorrectCase(): void
    {
        self::assertSame(DatabaseDriver::MySQL, DatabaseDriver::from('mysql'));
        self::assertSame(DatabaseDriver::MariaDB, DatabaseDriver::from('mariadb'));
        self::assertSame(DatabaseDriver::PostgreSQL, DatabaseDriver::from('pgsql'));
        self::assertSame(DatabaseDriver::SQLite, DatabaseDriver::from('sqlite'));
        self::assertSame(DatabaseDriver::SqlServer, DatabaseDriver::from('sqlserver'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(DatabaseDriver::tryFrom('oracle'));
        self::assertNull(DatabaseDriver::tryFrom(''));
        self::assertNull(DatabaseDriver::tryFrom('MySQL')); // case-sensitive
    }

    public function testCasesMethodReturnsFiveEntries(): void
    {
        self::assertCount(5, DatabaseDriver::cases());
    }
}
