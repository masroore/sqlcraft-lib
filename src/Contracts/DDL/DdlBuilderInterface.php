<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;

interface DdlBuilderInterface
{
    /** @return list<string> */
    public function toSql(DdlDialectInterface $dialect): array;

    public function execute(ConnectionInterface $connection): void;
}
