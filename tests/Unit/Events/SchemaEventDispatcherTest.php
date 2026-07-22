<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Events\BeforeDdlExecuted;
use SQLCraft\Events\CapabilityNotSupportedEvent;
use SQLCraft\Events\SchemaChangedEvent;
use SQLCraft\Events\SchemaEventDispatcher;

final class SchemaEventDispatcherTest extends TestCase
{
    public function test_it_maps_schema_lifecycle_calls_to_typed_events(): void
    {
        $received = [];
        $dispatcher = self::createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(3))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$received): object {
                $received[] = $event;

                return $event;
            },
        );
        $connection = self::createMock(ConnectionInterface::class);
        $events = new SchemaEventDispatcher($dispatcher);

        $events->beforeDdlExecuted($connection, 'CREATE TABLE users (id INT)', 'CreateTableBuilder');
        $events->schemaChanged($connection, 'DDL', 'CreateTableBuilder', 'CREATE');
        $events->capabilityNotSupported('routine', 'sqlite', '3.0');

        self::assertInstanceOf(BeforeDdlExecuted::class, $received[0]);
        self::assertInstanceOf(SchemaChangedEvent::class, $received[1]);
        self::assertInstanceOf(CapabilityNotSupportedEvent::class, $received[2]);
        self::assertSame('routine', $received[2]->capability);
    }
}
