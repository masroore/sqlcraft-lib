<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use SQLCraft\Events\SimpleEventDispatcher;
use SQLCraft\Events\SimpleListenerProvider;

final class SimpleEventDispatcherTest extends TestCase
{
    public function test_it_dispatches_matching_listeners_by_priority_and_registration_order(): void
    {
        $provider = new SimpleListenerProvider();
        $events = [];
        $provider->listen(TestEventContract::class, static function (object $event) use (&$events): void {
            $events[] = 'low';
        }, priority: 0);
        $provider->listen(TestEvent::class, static function (TestEvent $event) use (&$events): void {
            $events[] = 'high-first';
        }, priority: 100);
        $provider->listen(TestEvent::class, static function (TestEvent $event) use (&$events): void {
            $events[] = 'high-second';
        }, priority: 100);

        $returned = (new SimpleEventDispatcher($provider))->dispatch(new TestEvent());

        self::assertInstanceOf(TestEvent::class, $returned);
        self::assertSame(['high-first', 'high-second', 'low'], $events);
    }

    public function test_it_supports_interface_subscriptions(): void
    {
        $provider = new SimpleListenerProvider();
        $called = false;
        $provider->listen(TestEventContract::class, static function () use (&$called): void {
            $called = true;
        });

        (new SimpleEventDispatcher($provider))->dispatch(new TestEvent());

        self::assertTrue($called);
    }

    public function test_it_stops_propagation_before_the_next_listener(): void
    {
        $provider = new SimpleListenerProvider();
        $events = [];
        $provider->listen(StoppableTestEvent::class, static function (StoppableTestEvent $event) use (&$events): void {
            $events[] = 'first';
            $event->stopPropagation();
        }, priority: 10);
        $provider->listen(StoppableTestEvent::class, static function () use (&$events): void {
            $events[] = 'second';
        });

        (new SimpleEventDispatcher($provider))->dispatch(new StoppableTestEvent());

        self::assertSame(['first'], $events);
    }
}

interface TestEventContract
{
}

final class TestEvent implements TestEventContract
{
}

final class StoppableTestEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    #[\Override]
    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
