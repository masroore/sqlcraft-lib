<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

enum IndexType: string
{
    case PRIMARY = 'PRIMARY';
    case UNIQUE = 'UNIQUE';
    case INDEX = 'INDEX';
    case FULLTEXT = 'FULLTEXT';
    case SPATIAL = 'SPATIAL';
    case GIN = 'GIN';
    case GIST = 'GIST';
    case BRIN = 'BRIN';
}
