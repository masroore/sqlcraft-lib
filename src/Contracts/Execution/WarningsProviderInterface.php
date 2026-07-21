<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Collections\WarningCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface WarningsProviderInterface
{
    public function getWarnings(ConnectionInterface $connection): WarningCollection;
}
