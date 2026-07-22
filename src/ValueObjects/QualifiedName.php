<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;

final readonly class QualifiedName
{
    public function __construct(
        public Identifier $object,
        public ?Identifier $schema = null,
        public ?Identifier $catalog = null,
    ) {}

    public function qualify(int $depth = 3): self
    {
        if ($depth < 1 || $depth > 3) {
            throw new InvalidArgumentException('Qualification depth must be between 1 and 3.');
        }

        return match ($depth) {
            1 => new self($this->object),
            2 => new self($this->object, $this->schema),
            3 => new self($this->object, $this->schema, $this->catalog),
        };
    }
}
