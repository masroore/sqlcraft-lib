<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

interface QueryDialectInterface extends PaginationInterface
{
    /** @return list<string> */
    public function getKeywordList(): array;

    /** @return list<string> */
    public function getOperators(): array;

    /** @return list<string> */
    public function getSupportedAggregateFunctions(): array;

    public function getExplainSql(string $sql, bool $analyze = false): string;

    public function wrapWithTimeout(string $sql, int $milliseconds): ?string;
}
