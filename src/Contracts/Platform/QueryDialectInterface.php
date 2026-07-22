<?php
declare(strict_types=1);
namespace SQLCraft\Contracts\Platform;
interface QueryDialectInterface extends PaginationInterface
{
    public function getKeywordList(): array;
    public function getOperators(): array;
    public function getSupportedAggregateFunctions(): array;
    public function getExplainSql(string $sql, bool $analyze = false): string;
    public function wrapWithTimeout(string $sql, int $milliseconds): ?string;
}
