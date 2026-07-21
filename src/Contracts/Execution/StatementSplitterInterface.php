<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Contracts\Execution\StatementBatch;

interface StatementSplitterInterface
{
    public function split(string $sql, string $delimiter = ';'): StatementBatch;
}
