<?php

declare(strict_types=1);

namespace SQLCraft\Support;

final class ArrayUtil
{
    private function __construct() {}

    /**
     * @param  array<array-key, scalar|null>  $values
     */
    public static function isList(array $values): bool
    {
        return array_is_list($values);
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, scalar>
     */
    public static function withoutNulls(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
