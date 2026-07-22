<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

final readonly class StatementBatch
{
    /** @param list<string> $statements */
    public function __construct(public array $statements) {}
}
