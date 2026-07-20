<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Import;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Import\ImportOptions;
use SQLCraft\Import\ImportResult;

interface ImporterInterface
{
    public function import(
        ConnectionInterface $conn,
        ImportSourceInterface $source,
        ImportOptions $options,
    ): ImportResult;
}
