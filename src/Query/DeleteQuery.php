<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Query\QueryBuilderInterface;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class DeleteQuery implements QueryBuilderInterface
{
    /** @param list<WhereCondition> $where */
    public function __construct(public QualifiedName $table, /** @var list<WhereCondition> */ public array $where = [], public ?int $limit = null) {}

    public function withWhere(WhereCondition ...$where): self
    {
        return new self($this->table, array_values([...$this->where, ...$where]), $this->limit);
    }

    #[\Override]
    public function from(QualifiedName $table): static
    {
        return new self($table, $this->where, $this->limit);
    }

    #[\Override]
    public function where(WhereCondition ...$conditions): static
    {
        return $this->withWhere(...$conditions);
    }

    #[\Override]
    public function orderBy(OrderByClause ...$clauses): static
    {
        return $this;
    }

    #[\Override]
    public function paginate(PaginationParams $params): static
    {
        return new self($this->table, $this->where, $params->limit);
    }

    #[\Override]
    public function toSql(PlatformInterface $platform): array
    {
        return (new DeleteQueryRenderer($platform))->render($this);
    }
}
