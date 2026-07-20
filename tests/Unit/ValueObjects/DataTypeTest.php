<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\DataType;

final class DataTypeTest extends TestCase
{
    public function testUnsignedDefaultsToFalse(): void
    {
        self::assertFalse((new DataType('INT'))->unsigned);
    }

    public function testItStoresTypeShape(): void
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
}
