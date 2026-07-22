<?php

declare(strict_types=1);

namespace SQLCraft\Support;

final class TypeUtil
{
    private function __construct() {}

    public static function toInt(int|float|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $value = trim($value);

        return preg_match('/^[+-]?\\d+$/D', $value) === 1 ? (int) $value : null;
    }

    public static function toBool(bool|int|string|null $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
