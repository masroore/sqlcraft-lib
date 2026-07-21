<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

final class SimpleListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<array{listener: callable, priority: int, sequence: int}>> */
    private array $listeners = [];

    private int $sequence = 0;

    /** @param class-string $eventClass */
    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
            'sequence' => $this->sequence++,
        ];
    }

    /** @return iterable<callable> */
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        $matching = [];
        foreach ($this->listeners as $eventClass => $listeners) {
            if (!$event instanceof $eventClass) {
                continue;
            }
            foreach ($listeners as $listener) {
                $matching[] = $listener;
            }
        }

        usort(
            $matching,
            static fn (array $left, array $right): int => ($right['priority'] <=> $left['priority']) !== 0
                ? ($right['priority'] <=> $left['priority'])
                : ($left['sequence'] <=> $right['sequence']),
        );

        foreach ($matching as $listener) {
            yield $listener['listener'];
        }
    }
}
