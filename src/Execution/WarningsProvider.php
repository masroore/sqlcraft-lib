<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Collections\WarningCollection;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\WarningsProviderInterface;
use SQLCraft\DTO\QueryWarning;

final readonly class WarningsProvider implements WarningsProviderInterface
{
    #[\Override]
    public function getWarnings(ConnectionInterface $connection): WarningCollection
    {
        if (!in_array(strtolower($connection->getPlatformName()), ['mysql', 'mariadb'], true)) {
            return new WarningCollection([]);
        }

        $warnings = [];
        foreach ($connection->query('SHOW WARNINGS', streaming: false)->fetchAll() as $row) {
            $warnings[] = new QueryWarning(
                level: $this->stringValue($row['Level'] ?? $row['level'] ?? null),
                code: $this->intValue($row['Code'] ?? $row['code'] ?? null),
                message: $this->stringValue($row['Message'] ?? $row['message'] ?? null),
            );
        }

        return new WarningCollection($warnings);
    }

    private function stringValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => '',
        };
    }

    private function intValue(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && is_numeric($value) => (int) $value,
            is_float($value) => (int) $value,
            default => 0,
        };
    }
}
