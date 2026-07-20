<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\SchemaCollection;
use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Collections\TypeCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;

interface DatabaseInspectorInterface
{
    public function getSchemas(ConnectionInterface $conn): SchemaCollection;

    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection;

    public function getTypes(ConnectionInterface $conn, ?string $schema = null): TypeCollection;
}
