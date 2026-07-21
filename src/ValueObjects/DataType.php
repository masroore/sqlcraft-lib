<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SQLCraft\Support\StringUtil;

final readonly class DataType
{
    public function __construct(
        public string $name,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unsigned = false,
        public ?string $collation = null,
        public ?string $charset = null,
    ) {
        if (StringUtil::isBlank($name) || StringUtil::containsNullByte($name)) {
            throw new InvalidArgumentException('Data type name must not be blank or contain null bytes.');
        }
        if (preg_match('/^[A-Za-z]\w*(?:\s+[A-Za-z]\w*)*(?:\s*\([^;]*\))?$/', $name) !== 1) {
            throw new InvalidArgumentException('Data type name contains unsafe SQL syntax.');
        }
    }
}
