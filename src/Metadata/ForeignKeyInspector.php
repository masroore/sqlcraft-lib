<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\ForeignKeyCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\ValueObjects\QualifiedName;

/** @internal */
final class ForeignKeyInspector implements ForeignKeyInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getForeignKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection
    {
        return $this->collect($conn, $conn->getPlatform()->introspection()->getForeignKeysSql($table));
    }

    #[\Override]
    public function getReferencingKeys(ConnectionInterface $conn, QualifiedName $table): ForeignKeyCollection
    {
        return $this->collect($conn, $conn->getPlatform()->introspection()->getReferencingForeignKeysSql($table));
    }

    private function collect(ConnectionInterface $conn, string $sql): ForeignKeyCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $foreignKeys = [];

        foreach ($rows as $row) {
            $foreignKey = $this->factory->createForeignKeyMeta($row);
            $foreignKeys[$foreignKey->constraintName] = $foreignKey;
        }

        return new ForeignKeyCollection($foreignKeys);
    }
}
