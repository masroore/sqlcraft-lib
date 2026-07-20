<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\IndexCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface IndexInspectorInterface
{
    public function getIndexes(ConnectionInterface $conn, QualifiedName $table): IndexCollection;
}
