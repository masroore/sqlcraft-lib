<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final readonly class SimpleEventDispatcher implements EventDispatcherInterface
{
    public function __construct(private SimpleListenerProvider $listenerProvider)
    {
    }

    #[\Override]
    public function dispatch(object $event): object
    {
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
