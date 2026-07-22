<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;

final readonly class IdentifierQuoter
{
    public function __construct(private QuotingInterface $platform) {}

    public function quote(Identifier $identifier): string
    {
        return $this->platform->quoteIdentifier($identifier);
    }

    public function quoteQualified(QualifiedName $name): string
    {
        $parts = array_values(array_filter([
            $name->catalog?->name,
            $name->schema?->name,
            $name->object->name,
        ], static fn (?string $part): bool => $part !== null));

        return implode('.', array_map(
            fn (string $part): string => $this->platform->quoteIdentifier(new Identifier($part)),
            $parts,
        ));
    }
}
