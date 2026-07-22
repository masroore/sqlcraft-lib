<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Events\ExportFinishedEvent;
use SQLCraft\Events\ExportProgressEvent;
use SQLCraft\Events\ExportStartedEvent;
use SQLCraft\Events\ImportExportEventDispatcher;
use SQLCraft\Events\ImportFailedEvent;
use SQLCraft\Events\ImportFinishedEvent;
use SQLCraft\Events\ImportProgressEvent;
use SQLCraft\Events\ImportStartedEvent;

final class ImportExportEventDispatcherTest extends TestCase
{
    public function test_dispatches_typed_import_and_export_events(): void
    {
        $connection = self::createMock(ConnectionInterface::class);
        $source = new \stdClass;
        $target = new \stdClass;
        $events = [];
        $dispatcher = self::createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::exactly(7))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$events): object {
                $events[] = $event;

                return $event;
            },
        );
        $emitter = new ImportExportEventDispatcher($dispatcher);

        $emitter->importStarted($connection, $source, 10);
        $emitter->importProgress($connection, 5, 1, 0.5);
        $emitter->importFinished($connection, 1, [], 0.6);
        $emitter->importFailed($connection, new \RuntimeException('failed'), 'BAD SQL', 0.7);
        $emitter->exportStarted($connection, $target, 'csv', ['orders']);
        $emitter->exportProgress($connection, 1, 2, 0.8);
        $emitter->exportFinished($connection, 1, 2, 0.9);

        self::assertInstanceOf(ImportStartedEvent::class, $events[0]);
        self::assertInstanceOf(ImportProgressEvent::class, $events[1]);
        self::assertInstanceOf(ImportFinishedEvent::class, $events[2]);
        self::assertInstanceOf(ImportFailedEvent::class, $events[3]);
        self::assertInstanceOf(ExportStartedEvent::class, $events[4]);
        self::assertInstanceOf(ExportProgressEvent::class, $events[5]);
        self::assertInstanceOf(ExportFinishedEvent::class, $events[6]);
    }
}
