<?php

declare(strict_types=1);

namespace SQLCraft\Platform;

use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\QueryDialectInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;

final readonly class PlatformRoles
{
    public function __construct(public DdlDialectInterface $ddl, public IntrospectionDialectInterface $introspection, public QueryDialectInterface $queryDialect, public QuotingInterface $quoting, public TypeMapperInterface $types) {}

    public function withDdl(DdlDialectInterface $role): self
    {
        return new self($role, $this->introspection, $this->queryDialect, $this->quoting, $this->types);
    }

    public function withIntrospection(IntrospectionDialectInterface $role): self
    {
        return new self($this->ddl, $role, $this->queryDialect, $this->quoting, $this->types);
    }

    public function withQueryDialect(QueryDialectInterface $role): self
    {
        return new self($this->ddl, $this->introspection, $role, $this->quoting, $this->types);
    }

    public function withQuoting(QuotingInterface $role): self
    {
        return new self($this->ddl, $this->introspection, $this->queryDialect, $role, $this->types);
    }

    public function withTypes(TypeMapperInterface $role): self
    {
        return new self($this->ddl, $this->introspection, $this->queryDialect, $this->quoting, $role);
    }
}
