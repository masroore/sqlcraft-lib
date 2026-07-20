<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\ViewCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface ViewInspectorInterface
{
    public function getViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection;

    public function getViewDefinition(ConnectionInterface $conn, QualifiedName $view): string;

    public function getMaterializedViews(ConnectionInterface $conn, ?string $schema = null): ViewCollection;
}
