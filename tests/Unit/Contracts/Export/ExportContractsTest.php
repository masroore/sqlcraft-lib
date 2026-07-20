<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts\Export;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Export\ExporterInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;

final class ExportContractsTest extends TestCase
{
    public function testFormatWriterPortExposesTheExportLifecycle(): void
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

    public function testExporterPortExposesTheTopLevelExportOperation(): void
    {
        self::assertSame(['export'], $this->methodNames(ExporterInterface::class));
    }

    /**
     * @param class-string $interface
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
