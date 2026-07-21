<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class ExtensionMissingException extends ImportExportException
{
    public function __construct(string $extension)
    {
        parent::__construct(sprintf('PHP extension "%s" is required for this operation.', $extension));
    }
}
