<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Query\QueryBuilderInterface;
use SQLCraft\Import\UpsertMode;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class InsertQuery implements QueryBuilderInterface
{
    /** @param list<string> $columns @param list<list<mixed>> $rows */
    public function __construct(
        public QualifiedName $table,
        public array $columns,
        /** @var list<list<mixed>> */
        public array $rows = [],
        public ?string $selectSql = null,
        public UpsertMode $upsertMode = UpsertMode::Insert,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(QualifiedName $table, array $row, UpsertMode $mode = UpsertMode::Insert): self
    {
        return new self($table, array_keys($row), [array_values($row)], upsertMode: $mode);
    }

    /** @param list<mixed> $row */
    public function values(array $row): self
    {
        return new self($this->table, $this->columns, [...$this->rows, $row], $this->selectSql, $this->upsertMode);
    }

    #[\Override]
    public function from(QualifiedName $table): static
    {
        return new self($table, $this->columns, $this->rows, $this->selectSql, $this->upsertMode);
    }

    #[\Override]
    public function where(WhereCondition ...$conditions): static
    {
        return $this;
    }

    #[\Override]
    public function orderBy(OrderByClause ...$clauses): static
    {
        return $this;
    }

    #[\Override]
    public function paginate(PaginationParams $params): static
    {
        return $this;
    }

    #[\Override]
    public function toSql(PlatformInterface $platform): array
    {
        return (new InsertQueryRenderer($platform))->render($this);
    }
}
