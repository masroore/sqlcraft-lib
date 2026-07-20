<?php

declare(strict_types=1);

namespace SQLCraft\Export;

enum TableSectionStyle
{
    case None;
    case DropCreate;
    case Create;
}
