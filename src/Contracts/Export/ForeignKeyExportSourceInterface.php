<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Export;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\DTO\ForeignKeyMeta;

interface ForeignKeyExportSourceInterface
{
    /** @return iterable<ForeignKeyMeta> */
    public function getForeignKeys(ConnectionInterface $connection, string $table, ?string $schema = null): iterable;
}
