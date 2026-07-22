<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\DefaultValueKind;

final class DefaultValueTest extends TestCase
{
    public function test_factories_represent_all_default_kinds(): void
    {
        self::assertSame(DefaultValueKind::NULL_VALUE, DefaultValue::nullValue()->kind);
        self::assertNull(DefaultValue::nullValue()->value);
        self::assertSame('', DefaultValue::emptyString()->value);
        self::assertSame('42', DefaultValue::literal('42')->value);
        self::assertSame('CURRENT_TIMESTAMP', DefaultValue::expression('CURRENT_TIMESTAMP')->value);
        self::assertSame('order_id_seq', DefaultValue::sequenceNext('order_id_seq')->value);
    }

    public function test_constructor_rejects_inconsistent_discriminators(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DefaultValue(DefaultValueKind::NULL_VALUE, 'unexpected');
    }

    public function test_invalid_empty_string_defaults_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DefaultValue(DefaultValueKind::EMPTY_STRING, 'unexpected');
    }

    public function test_non_null_kinds_require_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DefaultValue(DefaultValueKind::LITERAL);
    }
}
