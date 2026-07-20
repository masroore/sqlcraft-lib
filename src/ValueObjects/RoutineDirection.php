<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum RoutineDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case INOUT = 'INOUT';
}
