<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Events\BeforeQueryExecuted;
use SQLCraft\Events\ObservabilityEvent;
use SQLCraft\Events\SQLCraftEventInterface;
use SQLCraft\Exceptions\OperationCancelledException;

final class EventTaxonomyTest extends TestCase
{
    public function test_observability_events_use_the_common_marker(): void
    {
        $event = $this->createMock(ObservabilityEvent::class);

        self::assertInstanceOf(SQLCraftEventInterface::class, $event);
    }

    public function test_interception_cancellation_stops_propagation_and_stores_reason(): void
    {
        $event = new BeforeQueryExecuted(
            $this->createMock(ConnectionInterface::class),
            'SELECT 1',
            [],
            'SELECT',
        );
        $event->cancel('maintenance window');

        self::assertTrue($event->isCancelled());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('maintenance window', $event->cancelReason);
    }

    public function test_before_query_can_replace_sql_and_parameters(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $event = new BeforeQueryExecuted($connection, 'SELECT 1', [], 'SELECT');
        $event->replaceSql('SELECT * FROM tenants WHERE tenant_id = ?', [42]);

        self::assertSame('SELECT * FROM tenants WHERE tenant_id = ?', $event->getSql());
        self::assertSame([42], $event->getParams());
    }

    public function test_cancellation_exception_is_typed(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new OperationCancelledException);
    }
}
