<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SQLCraft\Support\ArrayUtil;
use SQLCraft\Support\StringUtil;
use SQLCraft\Support\TypeUtil;

final class SupportUtilTest extends TestCase
{
    public function testStringUtilDetectsBlankValuesAndNullBytes(): void
    {
        self::assertTrue(StringUtil::isBlank(null));
        self::assertTrue(StringUtil::isBlank(" \t\n"));
        self::assertFalse(StringUtil::isBlank('sqlcraft'));
        self::assertTrue(StringUtil::containsNullByte("safe\0unsafe"));
        self::assertFalse(StringUtil::containsNullByte('safe'));
    }

    public function testStringUtilTrimsNonBlankValuesToNull(): void
    {
        self::assertNull(StringUtil::trimToNull(null));
        self::assertNull(StringUtil::trimToNull('   '));
        self::assertSame('sqlcraft', StringUtil::trimToNull(' sqlcraft '));
    }

    public function testTypeUtilConvertsIntegerValuesConservatively(): void
    {
        self::assertNull(TypeUtil::toInt(null));
        self::assertSame(7, TypeUtil::toInt(7));
        self::assertSame(7, TypeUtil::toInt(7.9));
        self::assertSame(-7, TypeUtil::toInt(' -7 '));
        self::assertNull(TypeUtil::toInt('7.9'));
        self::assertNull(TypeUtil::toInt('not an integer'));
    }

    public function testTypeUtilConvertsCommonBooleanRepresentations(): void
    {
        self::assertNull(TypeUtil::toBool(null));
        self::assertTrue(TypeUtil::toBool(true));
        self::assertFalse(TypeUtil::toBool(0));
        self::assertTrue(TypeUtil::toBool(1));
        self::assertTrue(TypeUtil::toBool(' YES '));
        self::assertFalse(TypeUtil::toBool('off'));
        self::assertNull(TypeUtil::toBool('unknown'));
    }

    public function testArrayUtilRecognizesLists(): void
    {
        self::assertTrue(ArrayUtil::isList([]));
        self::assertTrue(ArrayUtil::isList(['a', 'b']));
        self::assertFalse(ArrayUtil::isList([1 => 'a']));
        self::assertFalse(ArrayUtil::isList(['name' => 'sqlcraft']));
    }

    public function testArrayUtilRemovesNullValuesAndPreservesKeys(): void
    {
        self::assertSame(
            ['name' => 'sqlcraft', 'enabled' => true, 'count' => 0],
            ArrayUtil::withoutNulls([
                'name' => 'sqlcraft',
                'description' => null,
                'enabled' => true,
                'count' => 0,
            ]),
        );
    }
}
