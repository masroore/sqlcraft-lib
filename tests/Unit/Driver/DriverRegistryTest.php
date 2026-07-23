<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Driver\DriverInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Enums\DatabaseDriver;
use SQLCraft\Exceptions\DriverNotFoundException;
use SQLCraft\Exceptions\DuplicateRegistrationException;
use SQLCraft\ValueObjects\ConnectionParameters;

final class DriverRegistryTest extends TestCase
{
    public function test_it_registers_and_retrieves_drivers_by_name(): void
    {
        $driver = new class () implements DriverInterface {
            #[\Override]
            public function buildDsn(ConnectionParameters $params): string
            {
                return 'fake:';
            }

            #[\Override]
            public function connect(ConnectionParameters $params, ?string $name = null): ConnectionInterface
            {
                throw new \LogicException();
            }

            #[\Override]
            public function getPlatform(ConnectionInterface $connection): PlatformInterface
            {
                throw new \LogicException();
            }

            #[\Override]
            public function getName(): string
            {
                return 'fake';
            }

            #[\Override]
            public function getPdoDriverNames(): array
            {
                return ['fake'];
            }
        };
        $registry = new DriverRegistry([$driver]);

        self::assertSame($driver, $registry->get('fake'));
        self::assertSame(['fake'], $registry->getRegisteredNames());
    }

    public function test_it_rejects_a_duplicate_registered_driver(): void
    {
        $first = $this->fakeDriver();
        $second = $this->fakeDriver();
        $registry = new DriverRegistry([$first]);
        $this->expectException(DuplicateRegistrationException::class);
        $registry->register($second);
        self::assertSame(['fake'], $registry->getRegisteredNames());
    }

    public function test_it_throws_for_an_unknown_driver(): void
    {
        $this->expectException(DriverNotFoundException::class);
        $this->expectExceptionMessage('Driver not found: missing.');
        (new DriverRegistry())->get('missing');
    }

    public function test_get_by_driver_delegates_to_get_using_backing_value(): void
    {
        $driver = $this->fakeDriver(); // getName() returns 'fake'
        $registry = new DriverRegistry([$driver]);
        $registry->registerAlias('sqlite', 'fake');

        self::assertSame($driver, $registry->getByDriver(DatabaseDriver::SQLite));
    }

    private function fakeDriver(): DriverInterface
    {
        return new class () implements DriverInterface {
            #[\Override]
            public function buildDsn(ConnectionParameters $params): string
            {
                return 'fake:';
            }

            #[\Override]
            public function connect(ConnectionParameters $params, ?string $name = null): ConnectionInterface
            {
                throw new \LogicException();
            }

            #[\Override]
            public function getPlatform(ConnectionInterface $connection): PlatformInterface
            {
                throw new \LogicException();
            }

            #[\Override]
            public function getName(): string
            {
                return 'fake';
            }

            #[\Override]
            public function getPdoDriverNames(): array
            {
                return ['fake'];
            }
        };
    }
}
