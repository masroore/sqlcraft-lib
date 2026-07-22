<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\IndexCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\IndexInspectorInterface;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class IndexInspector implements IndexInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getIndexes(ConnectionInterface $conn, QualifiedName $table): IndexCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getIndexesSql($table))->fetchAll();
        $indexes = [];

        foreach ($rows as $row) {
            $index = $this->factory->createIndexMeta($row);
            $indexes[$index->name] = $index;
        }

        return new IndexCollection($indexes);
    }
}
