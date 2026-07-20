<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\CheckConstraintCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\ValueObjects\QualifiedName;

interface CheckConstraintInspectorInterface
{
    public function getCheckConstraints(ConnectionInterface $conn, QualifiedName $table): CheckConstraintCollection;
}
