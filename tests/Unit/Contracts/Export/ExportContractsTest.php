<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Export\ExporterInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;

final class ExportContractsTest extends TestCase
{
    public function test_format_writer_port_exposes_the_export_lifecycle(): void
    {
        self::assertSame(
            [
                'getFormatName',
                'writeHeader',
                'writeTableHeader',
                'writeTableDdl',
                'writeRows',
                'writeTableFooter',
                'writeFooter',
            ],
            $this->methodNames(FormatWriterInterface::class),
        );
    }

    public function test_exporter_port_exposes_the_top_level_export_operation(): void
    {
        self::assertSame(['export'], $this->methodNames(ExporterInterface::class));
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
