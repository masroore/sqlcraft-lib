<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Import;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Import\ImportSourceInterface;

final class ImportSourceInterfaceTest extends TestCase
{
    public function test_import_source_port_exposes_stream_and_size_hint(): void
    {
        self::assertSame(
            ['openStream', 'getEstimatedSize'],
            $this->methodNames(ImportSourceInterface::class),
        );
    }

    /**
     * @param  class-string  $interface
     * @return list<string>
     */
    private function methodNames(string $interface): array
    {
        return array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($interface))->getMethods(),
        );
    }
}
