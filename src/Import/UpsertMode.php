<?php

declare(strict_types=1);

namespace SQLCraft\Import;

enum UpsertMode
{
    case Insert;
    case InsertOrIgnore;
    case InsertOrReplace;
}
