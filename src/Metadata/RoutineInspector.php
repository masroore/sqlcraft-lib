<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Collections\RoutineCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\DTO\RoutineMeta;
use SQLCraft\Exceptions\ObjectNotFoundException;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class RoutineInspector implements RoutineInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getFunctions(ConnectionInterface $conn, ?string $schema = null): RoutineCollection
    {
        return $this->collect($conn, $schema, 'FUNCTION');
    }

    #[\Override]
    public function getProcedures(ConnectionInterface $conn, ?string $schema = null): RoutineCollection
    {
        return $this->collect($conn, $schema, 'PROCEDURE');
    }

    #[\Override]
    public function getRoutineDetail(ConnectionInterface $conn, QualifiedName $routine): RoutineMeta
    {
        $this->requireCapability($conn, Capability::Routine);
        $row = $conn->query($conn->getPlatform()->introspection()->getRoutineDetailSql($routine))->fetchAssoc();
        if ($row === null) {
            throw new ObjectNotFoundException(
                sprintf('Routine %s does not exist.', $routine->object->name),
                $routine->object->name,
            );
        }

        /** @var array<string, bool|float|int|string|null> $row */
        return $this->factory->createRoutineMeta($row);
    }

    private function collect(ConnectionInterface $conn, ?string $schema, string $type): RoutineCollection
    {
        $this->requireCapability($conn, Capability::Routine);
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->introspection()->getRoutinesSql($schema))->fetchAll();
        $routines = [];

        foreach ($rows as $row) {
            $routine = $this->factory->createRoutineMeta($row);
            if ($routine->type !== $type) {
                continue;
            }
            $routines[$routine->name] = $routine;
        }

        return new RoutineCollection($routines);
    }

    private function requireCapability(ConnectionInterface $connection, Capability $capability): void
    {
        try {
            $version = $connection->getServerVersion();
        } catch (\Throwable) {
            return;
        }

        $connection->getPlatform()->getCapabilitySet($version)->require($capability);
    }
}
