<?php

declare(strict_types=1);

namespace SQLCraft\Export;

enum DataStyle
{
    case None;
    case TruncateInsert;
    case Insert;
    case InsertUpdate;
}
