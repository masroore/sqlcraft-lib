<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use SQLCraft\Metadata\MetadataInspectorSet;

final class StableApiTest extends TestCase
{
    public function test_stable_api_manifest_resolves_and_contains_every_allowlisted_method(): void
    {
        /** @var array<class-string, list<string>> $manifest */
        $manifest = require dirname(__DIR__, 3) . '/tests/Fixtures/stable-api.php';

        self::assertNotEmpty($manifest);
        foreach ($manifest as $class => $methods) {
            self::assertTrue(interface_exists($class) || class_exists($class) || enum_exists($class), $class);
            $reflection = new \ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method), sprintf('%s::%s disappeared.', $class, $method));
                self::assertTrue($reflection->getMethod($method)->isPublic(), sprintf('%s::%s is no longer public.', $class, $method));
            }
        }
    }

    public function test_stable_interfaces_do_not_depend_on_internal_engine_adapters(): void
    {
        /** @var array<class-string, list<string>> $manifest */
        $manifest = require dirname(__DIR__, 3) . '/tests/Fixtures/stable-api.php';
        foreach ($manifest as $class => $methods) {
            $reflection = new \ReflectionClass($class);
            if (! $reflection->isInterface()) {
                continue;
            }
            foreach ($reflection->getMethods() as $method) {
                foreach ([$method->getReturnType(), ...array_map(static fn (\ReflectionParameter $parameter): ?\ReflectionType => $parameter->getType(), $method->getParameters())] as $type) {
                    if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }
                    self::assertStringNotContainsString('SQLCraft\\Platform\\', $type->getName(), sprintf('%s::%s exposes a concrete platform adapter.', $class, $method->getName()));
                    if ($type->getName() !== MetadataInspectorSet::class) {
                        self::assertStringNotContainsString('SQLCraft\\Metadata\\', $type->getName(), sprintf('%s::%s exposes a concrete metadata adapter.', $class, $method->getName()));
                    }
                }
            }
        }
    }
}
