<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use SQLCraft\DatabaseSession;
use SQLCraft\SQLCraftFactory;

final class ExtensionSeamLivenessTest extends TestCase
{
    public function test_driver_registration_has_no_platform_name_metadata_switch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Driver/DriverRegistry.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('metadataFactoryFor', $source);
        self::assertStringNotContainsString('match ($driver->getName())', $source);
    }

    public function test_query_events_are_not_sql_rewriters(): void
    {
        $source = $this->source('src');
        self::assertStringNotContainsString('BeforeQueryExecuted::replaceSql', $source);
        self::assertStringNotContainsString('function replaceSql(', $source);
    }

    public function test_core_does_not_define_plugin_scanning_or_bundle_registration(): void
    {
        $source = $this->source('src');
        self::assertStringNotContainsString('ServiceProviderInterface', $source);
        self::assertStringNotContainsString('ExtensionBundle', $source);
        self::assertStringNotContainsString('glob(', $source);
    }

    public function test_immutable_factory_and_session_do_not_expose_registration_methods(): void
    {
        foreach ([SQLCraftFactory::class, DatabaseSession::class] as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                self::assertFalse(str_starts_with($method->getName(), 'register'));
                self::assertFalse(str_starts_with($method->getName(), 'replace'));
            }
        }
    }

    private function source(string $relativeDirectory): string
    {
        $root = dirname(__DIR__, 3) . '/' . $relativeDirectory;
        $contents = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->getExtension() === 'php') {
                $contents[] = (string) file_get_contents($file->getPathname());
            }
        }

        return implode("\n", $contents);
    }
}
