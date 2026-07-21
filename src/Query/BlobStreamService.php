<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Capabilities\Capability;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class BlobStreamService
{
    public function __construct(private QueryExecutorInterface $executor)
    {
    }

    /**
     * @param array<string, scalar|null> $where
     * @return resource
     */
    public function download(ConnectionInterface $connection, QualifiedName $table, Identifier $column, array $where = [])
    {
        $connection->getPlatform()->getCapabilitySet($connection->getServerVersion())->require(Capability::BlobStreaming);
        $parts = [];
        if ($table->schema instanceof \SQLCraft\ValueObjects\Identifier) {
            $parts[] = $connection->quoteIdentifier($table->schema->name);
        }$parts[] = $connection->quoteIdentifier($table->object->name);
        $conditions = [];
        $params = [];
        foreach ($where as $name => $value) {
            $conditions[] = $connection->quoteIdentifier($name).' = ?';
            $params[] = $value;
        }
        $sql = 'SELECT '.$connection->quoteIdentifier($column->name).' FROM '.implode('.', $parts).($conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions));
        $values = $this->executor->query($connection, $sql, $params, buffered: false)->fetchColumn();
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to allocate BLOB stream.');
        }
        fwrite($stream, $this->contents($values));
        rewind($stream);
        return $stream;
    }

    /** @param list<mixed> $values */
    private function contents(array $values): string
    {
        if ($values === []) {
            return '';
        }

        if (is_string($values[0])) {
            return $values[0];
        }
        if (is_int($values[0]) || is_float($values[0]) || is_bool($values[0])) {
            return (string) $values[0];
        }

        return '';
    }

}
