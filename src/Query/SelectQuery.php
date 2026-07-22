<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\ValueObjects\QualifiedName;

final readonly class SelectQuery
{
    /**
     * @param  list<ColumnSelection>  $columns
     * @param  list<WhereCondition>  $where
     * @param  list<OrderByClause>  $orderBy
     * @param  list<string>  $groupBy
     */
    public function __construct(
        public QualifiedName $table,
        public array $columns = [],
        public array $where = [],
        public array $orderBy = [],
        public array $groupBy = [],
        public bool $distinct = false,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('Limit must be >= 1.');
        }
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('Offset must be >= 0.');
        }
    }

    public function withWhere(WhereCondition ...$conditions): self
    {
        /** @var list<WhereCondition> $where */
        $where = array_values([...$this->where, ...$conditions]);

        return new self($this->table, $this->columns, $where, $this->orderBy, $this->groupBy, $this->distinct, $this->limit, $this->offset);
    }

    public function withOrderBy(OrderByClause ...$clauses): self
    {
        /** @var list<OrderByClause> $orderBy */
        $orderBy = array_values([...$this->orderBy, ...$clauses]);

        return new self($this->table, $this->columns, $this->where, $orderBy, $this->groupBy, $this->distinct, $this->limit, $this->offset);
    }
}
