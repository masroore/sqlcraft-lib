<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\ValueObjects\ConnectionParameters;

final class BeforeConnectionOpened extends InterceptionEvent
{
    public function __construct(
        public readonly string $name,
        public readonly ConnectionParameters $parameters,
    ) {
    }
}
