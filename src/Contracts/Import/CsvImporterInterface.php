<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Import;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Import\CsvImportOptions;
use SQLCraft\Import\ImportResult;
use SQLCraft\ValueObjects\QualifiedName;

interface CsvImporterInterface
{
    public function importCsv(
        ConnectionInterface $conn,
        QualifiedName $table,
        ImportSourceInterface $source,
        CsvImportOptions $options,
    ): ImportResult;
}
