<?php

declare(strict_types=1);

namespace SQLCraft\Utilities;

use InvalidArgumentException;

final readonly class PaginationCalculator
{
    public function offset(int $page, int $limit): int
    {
        if ($page < 1 || $limit < 1) {
            throw new InvalidArgumentException('Page and limit must be >= 1.');
        }

        return ($page - 1) * $limit;
    }
}
