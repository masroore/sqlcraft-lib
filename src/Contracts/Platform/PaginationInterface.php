<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

interface PaginationInterface
{
    public function applyPagination(string $sql, int $limit, int $offset): string;

    public function applySingleRowLimit(string $sql, string $whereClause): string;
}
