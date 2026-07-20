<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\TriggerCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class TriggerInspector implements TriggerInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getTriggers(ConnectionInterface $conn, QualifiedName $table): TriggerCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getTriggersSql($table))->fetchAll();
        $triggers = [];

        foreach ($rows as $row) {
            $trigger = $this->factory->createTriggerMeta($row);
            $triggers[$trigger->name] = $trigger;
        }

        return new TriggerCollection($triggers);
    }
}
