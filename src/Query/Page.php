<?php

declare(strict_types=1);

namespace SQLCraft\Query;

final readonly class Page
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public array $rows,
        public PaginationParams $params,
        public ?int $totalRows,
        public bool $totalApprox,
        public bool $hasMore,
    ) {
    }

    public function totalPages(): ?int
    {
        return $this->totalRows === null ? null : (int) ceil($this->totalRows / $this->params->limit);
    }
}
