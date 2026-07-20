<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\TriggerCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface TriggerInspectorInterface
{
    public function getTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection;
}
