<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Enums\QueryKind;

final class BeforeQueryExecuted extends InterceptionEvent
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $sql,
        public readonly array $params,
        public readonly QueryKind $kind,
    ) {}

    /** @return array<string|int, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}
