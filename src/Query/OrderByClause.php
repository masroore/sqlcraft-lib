<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use SQLCraft\ValueObjects\Identifier;

final readonly class OrderByClause
{
    public function __construct(public Identifier $column, public bool $descending = false) {}
}
