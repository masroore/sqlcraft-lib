<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Execution;

use SQLCraft\Collections\ProcessCollection;

interface ProcessManagerInterface
{
    public function list(): ProcessCollection;

    public function kill(string|int $processId): void;
}
