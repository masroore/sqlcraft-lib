<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Identifier;

final class IdentifierTest extends TestCase
{
    public function test_it_stores_and_renders_an_identifier(): void
    {
        $identifier = new Identifier('users');

        self::assertSame('users', $identifier->name);
        self::assertSame('users', (string) $identifier);
        self::assertTrue($identifier->equals(new Identifier('users')));
        self::assertFalse($identifier->equals(new Identifier('Users')));
    }

    public function test_it_rejects_empty_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Identifier('');
    }

    public function test_it_rejects_null_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Identifier("users\0archive");
    }
}
