<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface SequenceInspectorInterface
{
    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection;
}
