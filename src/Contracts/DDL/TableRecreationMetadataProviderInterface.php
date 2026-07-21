<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface TableRecreationMetadataProviderInterface
{
    public function getDefinition(ConnectionInterface $connection, QualifiedName $table): TableRecreationDefinitionInterface;
}
