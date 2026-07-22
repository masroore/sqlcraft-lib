<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SQLCraft\Support\SecretRedactor;

final class SecretRedactorTest extends TestCase
{
    public function test_it_redacts_password_style_dsn_options(): void
    {
        $dsn = SecretRedactor::dsn('mysql:host=db;dbname=app;user=alice;password=secret');

        self::assertSame('mysql:host=db;dbname=app;user=alice;password=[redacted]', $dsn);
        self::assertStringNotContainsString('secret', $dsn);
    }
}
