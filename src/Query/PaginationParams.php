<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;

final readonly class PaginationParams
{
    public function __construct(public int $page, public int $limit)
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Page must be >= 1.');
        }
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be >= 1.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
