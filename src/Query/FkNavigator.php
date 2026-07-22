<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\DTO\BackwardKeyMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class FkNavigator
{
    /** @param array<string, mixed> $row */
    public function forward(ForeignKeyMeta $foreignKey, array $row): SelectQuery
    {
        $conditions = [];
        foreach ($foreignKey->sourceColumns as $index => $source) {
            $conditions[] = new WhereCondition(new Identifier($foreignKey->targetColumns[$index]), '=', $row[$source] ?? null);
        }

        return new SelectQuery(new QualifiedName(new Identifier($foreignKey->targetTable), $foreignKey->targetSchema === null ? null : new Identifier($foreignKey->targetSchema)), where: $conditions);
    }

    /** @param array<string, mixed> $row */
    public function backward(BackwardKeyMeta $foreignKey, array $row): SelectQuery
    {
        $conditions = [];
        foreach ($foreignKey->sourceColumns as $index => $source) {
            $conditions[] = new WhereCondition(new Identifier($source), '=', $row[$foreignKey->targetColumns[$index]] ?? null);
        }

        return new SelectQuery(new QualifiedName(new Identifier($foreignKey->sourceTable)), where: $conditions);
    }
}
