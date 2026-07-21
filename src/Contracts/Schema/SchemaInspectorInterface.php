<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Schema;

interface SchemaInspectorInterface
{
    /** @return array<string, mixed> */
    public function compare(mixed $expected, mixed $actual): array;

    /** @param array<string, mixed> $diff */
    public function describeDiff(array $diff): string;
}
