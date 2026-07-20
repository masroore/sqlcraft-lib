<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class ExportFailedException extends ImportExportException
{
    public function __construct(
        string $message,
        public readonly ?int $statementIndex = null,
        public readonly ?int $rowIndex = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
