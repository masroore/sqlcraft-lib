<?php

declare(strict_types=1);

namespace SQLCraft\Security;

use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Exceptions\InvalidOperatorException;

final readonly class OperatorValidator
{
    public function __construct(private PlatformInterface $platform) {}

    public function validate(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        if (! in_array($operator, $this->platform->getOperators(), true)) {
            throw new InvalidOperatorException(sprintf(
                "Operator '%s' is not permitted for platform '%s'.",
                $operator,
                $this->platform->getName(),
            ));
        }

        return $operator;
    }
}
