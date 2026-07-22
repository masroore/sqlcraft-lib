<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final class BeforeQueryExecuted extends InterceptionEvent
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public readonly ConnectionInterface $connection,
        private string $sql,
        private array $params,
        public readonly string $queryType,
    ) {}

    public function getSql(): string
    {
        return $this->sql;
    }

    /** @return array<string|int, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<string|int, mixed> $params */
    public function replaceSql(string $sql, array $params): void
    {
        $this->sql = $sql;
        $this->params = $params;
    }
}
