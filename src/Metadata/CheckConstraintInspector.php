<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Collections\CheckConstraintCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class CheckConstraintInspector implements CheckConstraintInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getCheckConstraints(ConnectionInterface $conn, QualifiedName $table): CheckConstraintCollection
    {
        $this->requireCapability($conn, Capability::CheckConstraints);
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->introspection()->getCheckConstraintsSql($table))->fetchAll();
        $constraints = [];

        foreach ($rows as $row) {
            $constraint = $this->factory->createCheckConstraintMeta($row);
            $constraints[$constraint->name] = $constraint;
        }

        return new CheckConstraintCollection($constraints);
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
