<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Platform;

use SQLCraft\DTO\ColumnMeta;
use SQLCraft\ValueObjects\Identifier;

interface QuotingInterface
{
    public function quoteIdentifier(Identifier $identifier): string;

    public function quoteValue(mixed $value): string;

    public function quoteBinary(string $bytes): string;

    public function convertFieldIn(ColumnMeta $column, string $expression): string;

    public function convertFieldOut(ColumnMeta $column, string $expression): string;
}
