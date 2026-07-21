<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Query\OrderByClause;
use SQLCraft\Query\PaginationParams;
use SQLCraft\Query\WhereCondition;
use SQLCraft\ValueObjects\QualifiedName;

interface QueryBuilderInterface
{
    public function from(QualifiedName $table): static;

    public function where(WhereCondition ...$conditions): static;

    public function orderBy(OrderByClause ...$clauses): static;

    public function paginate(PaginationParams $params): static;

    /** @return array{sql: string, params: list<mixed>} */
    public function toSql(PlatformInterface $platform): array;
}
