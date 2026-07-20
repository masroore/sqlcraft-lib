<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum DefaultValueKind: string
{
    case NULL_VALUE = 'null';
    case EMPTY_STRING = 'empty-string';
    case LITERAL = 'literal';
    case EXPRESSION = 'expression';
    case SEQUENCE_NEXT = 'sequence-next';
}
