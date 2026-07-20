<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Export;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Export\DumpOptions;

interface ExporterInterface
{
    public function export(ConnectionInterface $conn, SinkInterface $sink, DumpOptions $options): void;
}
