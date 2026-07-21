<?php

declare(strict_types=1);

namespace SQLCraft\Utilities;

use InvalidArgumentException;
use SQLCraft\ValueObjects\Identifier;

final readonly class IdentifierSanitizer
{
    public function sanitize(string $name): Identifier
    {
        $name = trim($name);
        if ($name === '' || preg_match('/[\x00\x1f\x7f]/', $name) === 1) {
            throw new InvalidArgumentException('Identifier cannot be empty or contain control characters.');
        }

        return new Identifier($name);
    }
}
