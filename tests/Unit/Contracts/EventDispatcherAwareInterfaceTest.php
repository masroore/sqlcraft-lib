<?php

declare(strict_types=1);

namespace SQLCraft\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use SQLCraft\Contracts\Events\EventDispatcherAwareInterface;

final class EventDispatcherAwareInterfaceTest extends TestCase
{
    public function testImplementationsCanReceiveApsr14Dispatcher(): void
    {
        $dispatcher = new class () implements EventDispatcherInterface {
            #[\Override]
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $aware = new class () implements EventDispatcherAwareInterface {
            public ?EventDispatcherInterface $dispatcher = null;

            #[\Override]
            public function setEventDispatcher(EventDispatcherInterface $dispatcher): void
            {
                $this->dispatcher = $dispatcher;
            }
        };

        $aware->setEventDispatcher($dispatcher);

        self::assertSame($dispatcher, $aware->dispatcher);
    }
}
