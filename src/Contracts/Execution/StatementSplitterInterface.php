<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

interface StatementSplitterInterface
{
    public function split(string $sql, string $delimiter = ';'): StatementBatch;
}
