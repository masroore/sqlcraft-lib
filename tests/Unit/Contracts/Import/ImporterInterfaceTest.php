<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Import\ImporterInterface;

final class ImporterInterfaceTest extends TestCase
{
    public function testImporterPortExposesTheImportOperation(): void
    {
        $reflection = new \ReflectionClass(ImporterInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(['import'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));
    }
}
