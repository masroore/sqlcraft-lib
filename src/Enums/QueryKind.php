<?php

declare(strict_types=1);

namespace SQLCraft\Enums;

enum QueryKind: string
{
    case Select = 'select';
    case Dml = 'dml';
    case Ddl = 'ddl';
    case Administrative = 'administrative';
}
