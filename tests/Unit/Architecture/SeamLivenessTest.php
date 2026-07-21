<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Metadata\MetadataCacheInterface;
use SQLCraft\Export\DumpOptions;

final class SeamLivenessTest extends TestCase
{
    public function testMetadataCacheInvalidationMethodsHaveExportedConsumers(): void
    {
        $source = $this->source('src/Schema');

        foreach (['invalidateTable', 'invalidateDatabase', 'clear'] as $method) {
            self::assertStringContainsString(
                '->' . $method . '(',
                $source,
                sprintf('Metadata cache method %s has no caller.', $method),
            );
        }

        self::assertNotEmpty((new \ReflectionClass(MetadataCacheInterface::class))->getMethods());
    }

    public function testEveryDumpOptionFlagIsReadByExportCode(): void
    {
        $options = new \ReflectionClass(DumpOptions::class);
        $exportSource = $this->source('src/Export', except: ['DumpOptions.php']);

        foreach ($options->getProperties() as $property) {
            if (!$property->isPromoted() || in_array($property->getName(), ['format', 'scope'], true)) {
                continue;
            }

            self::assertStringContainsString(
                '->' . $property->getName(),
                $exportSource,
                sprintf('DumpOptions flag %s has no export consumer.', $property->getName()),
            );
        }
    }

    public function testEveryConcreteEventIsConstructedByAnApplicationPath(): void
    {
        $eventDirectory = dirname(__DIR__, 3) . '/src/Events';
        $source = $this->source('src', except: []);

        $eventFiles = glob($eventDirectory . '/*.php');
        foreach ($eventFiles === false ? [] : $eventFiles as $file) {
            $class = basename($file, '.php');
            /** @var class-string $eventClass */
            $eventClass = 'SQLCraft\\Events\\' . $class;
            $reflection = new \ReflectionClass($eventClass);
            if ($reflection->isAbstract() || $reflection->isInterface() || str_ends_with($class, 'Dispatcher')) {
                continue;
            }

            self::assertStringContainsString(
                'new ' . $class . '(',
                $source,
                sprintf('Event %s has no construction/dispatch path.', $class),
            );
        }
    }

    public function testAdvertisedCapabilitiesBelongToTheClosedCapabilityEnum(): void
    {
        $enumCases = array_fill_keys(array_map(static fn (Capability $case): string => $case->name, Capability::cases()), true);

        $platformFiles = glob(dirname(__DIR__, 3) . '/src/Platform/*Platform.php');
        foreach ($platformFiles === false ? [] : $platformFiles as $file) {
            $platformSource = file_get_contents($file);
            self::assertIsString($platformSource);
            preg_match_all('/Capability::(\w+)/', $platformSource, $matches);

            foreach (array_unique($matches[1]) as $name) {
                self::assertArrayHasKey(
                    $name,
                    $enumCases,
                    sprintf('%s advertises unknown capability %s.', basename($file), $name),
                );
            }
        }
    }

    /** @param list<string> $except */
    private function source(string $relativeDirectory, array $except = []): string
    {
        $root = dirname(__DIR__, 3) . '/' . $relativeDirectory;
        $contents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $file = $file->getPathname();
            if (in_array(basename($file), $except, true)) {
                continue;
            }
            $contents[] = (string) file_get_contents($file);
        }

        return implode("\n", $contents);
    }
}
