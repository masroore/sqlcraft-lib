<?php
declare(strict_types=1);
namespace SQLCraft\Tests\Unit\Architecture;
use PHPUnit\Framework\TestCase;
final class AdminerExtensionMatrixTest extends TestCase
{
    public function test_revised_matrix_contains_the_complete_adminer_hook_inventory(): void
    {
        $path=dirname(__DIR__,3).'/docs/other/plans/extensions-revised/02-adminer-5.5.0-hook-matrix.md';
        $text=file_get_contents($path); self::assertIsString($text); preg_match_all('/^\| `([^`]+)\(/m',$text,$matches); $names=array_values(array_unique(array_map(static fn(string $signature):string=>strtok($signature,'('),$matches[1]))); self::assertCount(79,$names); self::assertSame(['dumpFormat','dumpOutput','editRowPrint','editFunctions','config'],array_values(array_filter(['dumpFormat','dumpOutput','editRowPrint','editFunctions','config'],static fn(string $name):bool=>in_array($name,$names,true))));
    }
}
