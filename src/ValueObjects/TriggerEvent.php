<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum TriggerEvent: string
{
    case INSERT = 'INSERT';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case TRUNCATE = 'TRUNCATE';
}
