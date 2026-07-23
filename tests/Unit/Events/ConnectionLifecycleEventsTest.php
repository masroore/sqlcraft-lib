<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use SQLCraft\Connection\PdoConnectionFactory;
use SQLCraft\Connection\PdoExceptionTranslator;
use SQLCraft\Events\BeforeConnectionOpened;
use SQLCraft\Events\ConnectionClosedEvent;
use SQLCraft\Events\ConnectionEventDispatcher;
use SQLCraft\Events\ConnectionFailedEvent;
use SQLCraft\Events\ConnectionOpenedEvent;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;
use SQLCraft\Events\TransactionBeganEvent;
use SQLCraft\Events\TransactionCommittedEvent;
use SQLCraft\Events\TransactionRolledBackEvent;
use SQLCraft\Exceptions\OperationCancelledException;
use SQLCraft\Platform\SqlitePlatform;
use SQLCraft\ValueObjects\ConnectionParameters;

final class ConnectionLifecycleEventsTest extends TestCase
{
    public function test_factory_dispatches_opened_and_close_events_without_credentials(): void
    {
        $provider = new SimpleListenerProvider();
        $events = [];
        $provider->listen(ConnectionOpenedEvent::class, static function (ConnectionOpenedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $provider->listen(ConnectionClosedEvent::class, static function (ConnectionClosedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $connection = (new PdoConnectionFactory(new PdoExceptionTranslator(), new ConnectionEventDispatcher(new SimpleEventDispatcher($provider))))->connect(
            'sqlite::memory:',
            new ConnectionParameters(database: 'app', username: 'alice', password: 'secret'),
            new SqlitePlatform(),
            'app',
        );

        $connection->close();
        $connection->close();

        self::assertCount(2, $events);
        self::assertInstanceOf(ConnectionOpenedEvent::class, $events[0]);
        self::assertInstanceOf(ConnectionClosedEvent::class, $events[1]);
        self::assertStringNotContainsString('secret', serialize($events));
    }

    public function test_factory_dispatches_failure_event(): void
    {
        $provider = new SimpleListenerProvider();
        $failed = null;
        $provider->listen(ConnectionFailedEvent::class, static function (ConnectionFailedEvent $event) use (&$failed): void {
            $failed = $event;
        });
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator(), new ConnectionEventDispatcher(new SimpleEventDispatcher($provider)));

        try {
            $factory->connect('sqlite:/path/that/does/not/exist/db.sqlite', new ConnectionParameters(), new SqlitePlatform());
        } catch (\Throwable) {
        }

        self::assertInstanceOf(ConnectionFailedEvent::class, $failed);
    }

    public function test_connection_opening_can_be_cancelled_before_pdo_creation(): void
    {
        $provider = new SimpleListenerProvider();
        $provider->listen(BeforeConnectionOpened::class, static function (BeforeConnectionOpened $event): void {
            $event->cancel('disabled');
        });
        $factory = new PdoConnectionFactory(new PdoExceptionTranslator(), new ConnectionEventDispatcher(new SimpleEventDispatcher($provider)));

        $this->expectException(OperationCancelledException::class);
        $factory->connect('sqlite::memory:', new ConnectionParameters(), new SqlitePlatform());
    }

    public function test_transaction_lifecycle_events_include_savepoint_data(): void
    {
        $provider = new SimpleListenerProvider();
        $received = [];
        foreach ([TransactionBeganEvent::class, TransactionCommittedEvent::class, TransactionRolledBackEvent::class] as $eventClass) {
            $provider->listen($eventClass, static function (object $event) use (&$received): void {
                $received[] = $event;
            });
        }
        $connection = (new PdoConnectionFactory(new PdoExceptionTranslator(), new ConnectionEventDispatcher(new SimpleEventDispatcher($provider))))->connect(
            'sqlite::memory:',
            new ConnectionParameters(),
            new SqlitePlatform(),
        );
        $outer = $connection->beginTransaction('SERIALIZABLE');
        $inner = $connection->beginTransaction();
        $inner->rollback();
        $outer->commit();

        self::assertCount(4, $received);
        self::assertInstanceOf(TransactionBeganEvent::class, $received[0]);
        self::assertNull($received[0]->savepoint);
        self::assertInstanceOf(TransactionBeganEvent::class, $received[1]);
        self::assertNotNull($received[1]->savepoint);
        self::assertInstanceOf(TransactionRolledBackEvent::class, $received[2]);
        self::assertInstanceOf(TransactionCommittedEvent::class, $received[3]);
    }
}
