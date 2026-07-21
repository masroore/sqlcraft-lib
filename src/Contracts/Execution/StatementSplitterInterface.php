<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

interface StatementSplitterInterface
{
    /** @return StatementBatch */
    public function split(string $sql, string $delimiter = ';'): StatementBatch;
}
