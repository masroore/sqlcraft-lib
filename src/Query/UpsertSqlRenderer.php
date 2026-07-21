<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Import\UpsertMode;

final class UpsertSqlRenderer
{
    /**
     * @param list<string> $quotedColumns
     * @return array{prefix: string, suffix: string}
     */
    public static function clauses(PlatformInterface $platform, UpsertMode $mode, array $quotedColumns): array
    {
        return self::clausesForName($platform->getName(), $mode, $quotedColumns);
    }

    /**
     * @param list<string> $quotedColumns
     * @return array{prefix: string, suffix: string}
     */
    public static function clausesForName(string $platform, UpsertMode $mode, array $quotedColumns): array
    {
        if ($mode === UpsertMode::Insert) {
            return ['prefix' => 'INSERT', 'suffix' => ''];
        }
        return match ($platform) {
            'sqlite' => ['prefix' => $mode === UpsertMode::InsertOrIgnore ? 'INSERT OR IGNORE' : 'INSERT OR REPLACE', 'suffix' => ''],
            'mysql', 'mariadb' => ['prefix' => $mode === UpsertMode::InsertOrIgnore ? 'INSERT IGNORE' : 'REPLACE', 'suffix' => ''],
            'pgsql' => [
                'prefix' => 'INSERT',
                'suffix' => $mode === UpsertMode::InsertOrIgnore
                    ? ' ON CONFLICT DO NOTHING'
                    : ' ON CONFLICT (' . ($quotedColumns[0] ?? throw new InvalidArgumentException('Upsert requires at least one column.')) . ') DO UPDATE SET '
                        . implode(', ', array_map(static fn (string $column): string => $column . ' = EXCLUDED.' . $column, $quotedColumns)),
            ],
            'sqlserver' => ['prefix' => 'MERGE', 'suffix' => ''],
            default => throw new InvalidArgumentException(sprintf('Upsert mode %s is unsupported by %s.', $mode->name, $platform)),
        };
    }
}
