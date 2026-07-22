<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\SchemaCollection;
use SQLCraft\Collections\SequenceCollection;
use SQLCraft\Collections\TypeCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\DatabaseInspectorInterface;

/** @internal */
final class DatabaseInspector implements DatabaseInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory) {}

    #[\Override]
    public function getSchemas(ConnectionInterface $conn): SchemaCollection
    {
        $sql = $conn->getPlatform()->getSchemasSql();
        if ($sql === '') {
            return new SchemaCollection([]);
        }

        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $schemas = [];

        foreach ($rows as $row) {
            $schema = $this->factory->createSchemaMeta($row);
            $schemas[$schema->name] = $schema;
        }

        return new SchemaCollection($schemas);
    }

    #[\Override]
    public function getSequences(ConnectionInterface $conn, ?string $schema = null): SequenceCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getSequencesSql($schema))->fetchAll();
        $sequences = [];

        foreach ($rows as $row) {
            $sequence = $this->factory->createSequenceMeta($row);
            $sequences[$sequence->name] = $sequence;
        }

        return new SequenceCollection($sequences);
    }

    #[\Override]
    public function getTypes(ConnectionInterface $conn, ?string $schema = null): TypeCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getTypesSql($schema))->fetchAll();
        $types = [];

        foreach ($rows as $row) {
            $type = $this->factory->createDataType($row);
            $types[$type->name] = $type;
        }

        return new TypeCollection($types);
    }
}
