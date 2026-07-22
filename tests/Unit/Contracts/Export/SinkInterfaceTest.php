<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Export\SinkInterface;

final class SinkInterfaceTest extends TestCase
{
    public function test_sink_port_exposes_streaming_lifecycle(): void
    {
        self::assertSame(
            ['write', 'flush', 'close'],
            $this->methodNames(SinkInterface::class),
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
