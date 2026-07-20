<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\ForeignKeyCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface ForeignKeyInspectorInterface
{
    public function getForeignKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection;

    public function getReferencingKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection;
}
