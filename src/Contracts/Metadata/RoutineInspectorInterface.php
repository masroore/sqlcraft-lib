<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Metadata;

use SQLCraft\Collections\RoutineCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\ValueObjects\QualifiedName;

interface RoutineInspectorInterface
{
    public function getFunctions(ConnectionInterface $conn, ?string $schema = null): RoutineCollection;

    public function getProcedures(ConnectionInterface $conn, ?string $schema = null): RoutineCollection;

    public function getRoutineDetail(ConnectionInterface $conn, QualifiedName $routine): RoutineMeta;
}
