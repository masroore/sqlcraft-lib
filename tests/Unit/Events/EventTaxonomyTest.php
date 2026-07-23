<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Enums\QueryKind;
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
            QueryKind::Select,
        );
        $event->cancel('maintenance window');

        self::assertTrue($event->isCancelled());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('maintenance window', $event->cancelReason);
    }

    public function test_before_query_exposes_final_sql_and_parameters_as_read_only(): void
    {
        $event = new BeforeQueryExecuted($this->createMock(ConnectionInterface::class), 'SELECT 1', [42], QueryKind::Select);

        self::assertSame('SELECT 1', $event->getSql());
        self::assertSame([42], $event->getParams());
        self::assertFalse((new \ReflectionClass($event))->hasMethod('replaceSql'));
    }

    public function test_cancellation_exception_is_typed(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new OperationCancelledException);
    }
}
