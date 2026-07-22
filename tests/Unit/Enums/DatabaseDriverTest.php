<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use SQLCraft\Enums\DatabaseDriver;

final class DatabaseDriverTest extends TestCase
{
    public function test_all_cases_have_expected_backing_values(): void
    {
        self::assertSame('mysql', DatabaseDriver::MySQL->value);
        self::assertSame('mariadb', DatabaseDriver::MariaDB->value);
        self::assertSame('pgsql', DatabaseDriver::PostgreSQL->value);
        self::assertSame('sqlite', DatabaseDriver::SQLite->value);
        self::assertSame('sqlserver', DatabaseDriver::SqlServer->value);
    }

    public function test_from_produces_correct_case(): void
    {
        self::assertSame(DatabaseDriver::MySQL, DatabaseDriver::from('mysql'));
        self::assertSame(DatabaseDriver::MariaDB, DatabaseDriver::from('mariadb'));
        self::assertSame(DatabaseDriver::PostgreSQL, DatabaseDriver::from('pgsql'));
        self::assertSame(DatabaseDriver::SQLite, DatabaseDriver::from('sqlite'));
        self::assertSame(DatabaseDriver::SqlServer, DatabaseDriver::from('sqlserver'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull($this->tryFromValue('oracle'));
        self::assertNull($this->tryFromValue(''));
        self::assertNull($this->tryFromValue('MySQL')); // case-sensitive
    }

    public function test_cases_method_returns_five_entries(): void
    {
        self::assertCount(5, DatabaseDriver::cases());
    }

    private function tryFromValue(string $value): ?DatabaseDriver
    {
        return DatabaseDriver::tryFrom($value);
    }
}
