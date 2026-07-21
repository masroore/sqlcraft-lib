<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Query\QueryBuilderInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class UpdateQuery implements QueryBuilderInterface
{
    /** @param array<string, mixed> $assignments @param list<WhereCondition> $where */
    public function __construct(public QualifiedName $table, public array $assignments, /** @var list<WhereCondition> */ public array $where = [], public ?int $limit = null)
    {
    }
    public function set(string $column, mixed $value): self
    {
        return new self($this->table, [...$this->assignments, $column => $value], $this->where, $this->limit);
    }
    public function withWhere(WhereCondition ...$where): self
    {
        return new self($this->table, $this->assignments, array_values([...$this->where, ...$where]), $this->limit);
    }
    #[\Override] public function from(QualifiedName $table): static
    {
        return new self($table, $this->assignments, $this->where, $this->limit);
    }
    #[\Override] public function where(WhereCondition ...$conditions): static
    {
        return $this->withWhere(...$conditions);
    }
    #[\Override] public function orderBy(OrderByClause ...$clauses): static
    {
        return $this;
    }
    #[\Override] public function paginate(PaginationParams $params): static
    {
        return new self($this->table, $this->assignments, $this->where, $params->limit);
    }
    #[\Override] public function toSql(\SQLCraft\Contracts\Platform\PlatformInterface $platform): array
    {
        return (new UpdateQueryRenderer($platform))->render($this);
    }
}
