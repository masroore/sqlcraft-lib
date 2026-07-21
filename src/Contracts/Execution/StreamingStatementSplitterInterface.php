<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

interface StreamingStatementSplitterInterface
{
    /** @param resource $stream @return \Generator<int, string, void, void> */
    public function splitStream($stream, string $delimiter = ';'): \Generator;
}
