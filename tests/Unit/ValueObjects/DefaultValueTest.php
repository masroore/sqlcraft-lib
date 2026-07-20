<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\DefaultValue;
use SQLCraft\ValueObjects\DefaultValueKind;

final class DefaultValueTest extends TestCase
{
    public function testFactoriesRepresentAllDefaultKinds(): void
    {
        self::assertSame(DefaultValueKind::NULL_VALUE, DefaultValue::nullValue()->kind);
        self::assertNull(DefaultValue::nullValue()->value);
        self::assertSame('', DefaultValue::emptyString()->value);
        self::assertSame('42', DefaultValue::literal('42')->value);
        self::assertSame('CURRENT_TIMESTAMP', DefaultValue::expression('CURRENT_TIMESTAMP')->value);
        self::assertSame('order_id_seq', DefaultValue::sequenceNext('order_id_seq')->value);
    }

    public function testConstructorRejectsInconsistentDiscriminators(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DefaultValue(DefaultValueKind::NULL_VALUE, 'unexpected');
    }

    public function testNonNullKindsRequireValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DefaultValue(DefaultValueKind::LITERAL);
    }
}
