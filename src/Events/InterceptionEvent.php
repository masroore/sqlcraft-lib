<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use Psr\EventDispatcher\StoppableEventInterface;

abstract class InterceptionEvent implements SQLCraftEventInterface, StoppableEventInterface
{
    private bool $propagationStopped = false;
    private bool $cancelled = false;
    public string $cancelReason = '';

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    #[\Override]
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function cancel(string $reason = ''): void
    {
        $this->cancelled = true;
        $this->cancelReason = $reason;
        $this->stopPropagation();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
