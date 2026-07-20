<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum TriggerTiming: string
{
    case BEFORE = 'BEFORE';
    case AFTER = 'AFTER';
    case INSTEAD_OF = 'INSTEAD OF';
}
