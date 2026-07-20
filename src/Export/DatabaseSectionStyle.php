<?php

declare(strict_types=1);

namespace SQLCraft\Export;

enum DatabaseSectionStyle
{
    case None;
    case Use;
    case DropCreate;
    case Create;
}
