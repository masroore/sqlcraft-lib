<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class AdminerExtensionMatrixTest extends TestCase
{
    public function test_revised_matrix_contains_the_complete_adminer_hook_inventory(): void
    {
        $path = dirname(__DIR__, 3) . '/docs/other/plans/extensions-revised/02-adminer-5.5.0-hook-matrix.md';
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('Unable to read Adminer hook matrix.');
        }
        self::assertStringContainsString('Adminer 5.5.0', $text);
        preg_match_all('/^\| `([^`]+)\(/m', $text, $matches);
        self::assertCount(79, $matches[1]);
        $extractName = static function (string $signature): string {
            return explode('(', $signature, 2)[0];
        };
        $rawNames = array_map($extractName, $matches[1]);
        $names = array_values(array_unique($rawNames));
        self::assertCount(79, $names);
        self::assertSame($rawNames, $names, 'The parity matrix contains duplicate hook names.');
        self::assertSame(
            ['dumpFormat', 'dumpOutput', 'editRowPrint', 'editFunctions', 'config'],
            array_values(array_filter(
                ['dumpFormat', 'dumpOutput', 'editRowPrint', 'editFunctions', 'config'],
                static fn (string $name): bool => in_array($name, $names, true),
            )),
        );
    }
}
