<?php

declare(strict_types=1);

namespace SQLCraft\Metadata;

use SQLCraft\Collections\CharsetCollection;
use SQLCraft\Collections\CollationCollection;
use SQLCraft\Collections\DatabaseCollection;
use SQLCraft\Collections\ProcessCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\DTO\ServerInfo;
use SQLCraft\ValueObjects\Charset;
use SQLCraft\ValueObjects\Collation;

/** @internal */
final class ServerInspector implements ServerInspectorInterface
{
    public function __construct(private readonly MetadataFactoryInterface $factory)
    {
    }

    #[\Override]
    public function getServerInfo(ConnectionInterface $conn): ServerInfo
    {
        $platform = $conn->getPlatform();

        return new ServerInfo(
            version: $conn->getServerVersion(),
            platformName: $platform->getName(),
            flavor: $platform->getFlavor(),
            dataDirectory: null,
            timezone: null,
            charset: $platform->getDefaultCharset(),
            collation: $platform->getDefaultCollation(),
        );
    }

    #[\Override]
    public function getDatabases(ConnectionInterface $conn): DatabaseCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getDatabasesSql())->fetchAll();
        $databases = [];

        foreach ($rows as $row) {
            $database = $this->factory->createDatabaseMeta($row);
            $databases[$database->name] = $database;
        }

        return new DatabaseCollection($databases);
    }

    /** @return array<string, string> */
    #[\Override]
    public function getVariables(ConnectionInterface $conn): array
    {
        return $this->keyValueRows($conn->getPlatform()->getVariablesSql(), $conn);
    }

    /** @return array<string, string> */
    #[\Override]
    public function getStatus(ConnectionInterface $conn): array
    {
        $sql = $conn->getPlatform()->getStatusSql();
        if ($sql === '') {
            return [];
        }

        return $this->keyValueRows($sql, $conn);
    }

    #[\Override]
    public function getProcessList(ConnectionInterface $conn): ProcessCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getProcesslistSql())->fetchAll();
        $processes = [];

        foreach ($rows as $row) {
            $process = $this->factory->createProcessMeta($row);
            $processes[$process->id] = $process;
        }

        return new ProcessCollection($processes);
    }

    #[\Override]
    public function getCharsets(ConnectionInterface $conn): CharsetCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getCharsetsSql())->fetchAll();
        $charsets = [];

        foreach ($rows as $row) {
            /** @var array<string, bool|float|int|string|null> $row */
            $row = $this->normalizeRow($row);
            $name = $this->name($row, 'charset', 'character_set_name', 'name');
            $charsets[$name] = new Charset($name);
        }

        return new CharsetCollection($charsets);
    }

    #[\Override]
    public function getCollations(ConnectionInterface $conn, ?string $charset = null): CollationCollection
    {
        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($conn->getPlatform()->getCollationsSql($charset))->fetchAll();
        $collations = [];

        foreach ($rows as $row) {
            /** @var array<string, bool|float|int|string|null> $row */
            $row = $this->normalizeRow($row);
            $name = $this->name($row, 'collation', 'collation_name', 'name');
            $collations[$name] = new Collation($name);
        }

        return new CollationCollection($collations);
    }

    /** @return array<string, string> */
    private function keyValueRows(string $sql, ConnectionInterface $conn): array
    {
        if ($sql === '') {
            return [];
        }

        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = $conn->query($sql)->fetchAll();
        $values = [];

        foreach ($rows as $row) {
            /** @var array<string, bool|float|int|string|null> $row */
            $row = $this->normalizeRow($row);
            $name = $this->name($row, 'variable_name', 'name', 'key', 'status');
            $value = $row['value'] ?? $row['setting'] ?? $row['variable_value'] ?? '';
            $values[$name] = (string) $value;
        }

        return $values;
    }

    /** @param array<string, bool|float|int|string|null> $row */
    private function name(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, bool|float|int|string|null> $row
     * @return array<string, bool|float|int|string|null>
     */
    private function normalizeRow(array $row): array
    {
        /** @var array<string, bool|float|int|string|null> $normalized */
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }

        return $normalized;
    }
}
