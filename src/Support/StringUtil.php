<?php

declare(strict_types=1);

namespace SQLCraft\Support;

final class StringUtil
{
    private function __construct() {}

    public static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    public static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function containsNullByte(string $value): bool
    {
        return str_contains($value, "\0");
    }
}
