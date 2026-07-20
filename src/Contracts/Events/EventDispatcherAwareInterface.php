<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

interface EventDispatcherAwareInterface
{
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): void;
}
