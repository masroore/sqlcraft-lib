<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\DataType;

final class DataTypeTest extends TestCase
{
    public function test_unsigned_defaults_to_false(): void
    {
        self::assertFalse((new DataType('INT'))->unsigned);
    }

    public function test_it_stores_type_shape(): void
    {
        $type = new DataType(
            'VARCHAR',
            length: 255,
            unsigned: false,
            collation: 'utf8mb4_unicode_ci',
            charset: 'utf8mb4',
        );

        self::assertSame('VARCHAR', $type->name);
        self::assertSame(255, $type->length);
        self::assertFalse($type->unsigned);
        self::assertSame('utf8mb4_unicode_ci', $type->collation);
        self::assertSame('utf8mb4', $type->charset);
    }

    public function test_it_rejects_unsafe_type_syntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DataType('TEXT); DROP TABLE audit_log; --');
    }

    public function test_it_allows_common_parameterized_types(): void
    {
        self::assertSame("ENUM('open','closed')", (new DataType("ENUM('open','closed')"))->name);
    }
}
