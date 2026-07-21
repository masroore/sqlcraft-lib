<?php

declare(strict_types=1);

namespace SQLCraft\Execution;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\ExplainServiceInterface;
use SQLCraft\DTO\ExplainResult;

final readonly class ExplainService implements ExplainServiceInterface
{
    /** @param array<string|int, mixed> $params */
    #[\Override]
    public function explain(
        ConnectionInterface $connection,
        string $sql,
        array $params = [],
        bool $analyze = false,
    ): ExplainResult {
        $explainSql = $connection->getPlatform()->getExplainSql($sql, $analyze);
        $startedAt = hrtime(true);
        /** @var list<array<string, int|float|string|bool|null>> $rawRows */
        $rawRows = $connection->query($explainSql, $params, streaming: false)->fetchAll();
        $rows = [];
        foreach ($rawRows as $rawRow) {
            $row = [];
            foreach ($rawRow as $column => $value) {
                $row[$column] = $this->scalarValue($value);
            }
            $rows[] = $row;
        }

        return new ExplainResult(
            engine: $connection->getPlatformName(),
            rows: $rows,
            elapsedMs: (hrtime(true) - $startedAt) / 1_000_000,
        );
    }

    private function scalarValue(mixed $value): int|float|string|bool|null
    {
        return match (true) {
            $value === null,
            is_int($value),
            is_float($value),
            is_string($value),
            is_bool($value) => $value,
            default => null,
        };
    }
}
