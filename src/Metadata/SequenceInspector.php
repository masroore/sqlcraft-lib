<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\SequenceInspectorInterface;

/** @internal */
final class SequenceInspector implements SequenceInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection
    {
        $this->requireCapability($conn, Capability::Sequence);
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getSequencesSql($schema))->fetchAll();
        $sequences = [];

        foreach ($rows as $row) {
            $sequence = $this->factory->createSequenceMeta($row);
            $sequences[$sequence->name] = $sequence;
        }

        return new SequenceCollection($sequences);
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
