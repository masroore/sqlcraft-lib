<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum ForeignKeyAction: string
{
    case RESTRICT = 'RESTRICT';
    case CASCADE = 'CASCADE';
    case SET_NULL = 'SET NULL';
    case NO_ACTION = 'NO ACTION';
    case SET_DEFAULT = 'SET DEFAULT';
}
