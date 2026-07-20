<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;

final readonly class DefaultValue
{
    public function __construct(
        public DefaultValueKind $kind,
        public ?string $value = null,
    ) {
        if ($kind === DefaultValueKind::NULL_VALUE && $value !== null) {
            throw new InvalidArgumentException('NULL defaults cannot carry a value.');
        }

        if ($kind !== DefaultValueKind::NULL_VALUE && $value === null) {
            throw new InvalidArgumentException('Non-NULL defaults require a value.');
        }

        if ($kind === DefaultValueKind::EMPTY_STRING && $value !== '') {
            throw new InvalidArgumentException('Empty-string defaults require an empty value.');
        }
    }

    public static function nullValue(): self
    {
        return new self(DefaultValueKind::NULL_VALUE);
    }

    public static function emptyString(): self
    {
        return new self(DefaultValueKind::EMPTY_STRING, '');
    }

    public static function literal(string $value): self
    {
        return new self(DefaultValueKind::LITERAL, $value);
    }

    public static function expression(string $value): self
    {
        return new self(DefaultValueKind::EXPRESSION, $value);
    }

    public static function sequenceNext(string $sequence): self
    {
        return new self(DefaultValueKind::SEQUENCE_NEXT, $sequence);
    }
}
