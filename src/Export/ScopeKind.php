<?php

declare(strict_types=1);

namespace SQLCraft\Export;

enum ScopeKind
{
    case AllDatabases;
    case Database;
    case Tables;
    case FilteredResult;
}
