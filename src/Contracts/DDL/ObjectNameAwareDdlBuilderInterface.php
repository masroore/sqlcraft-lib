<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

interface ObjectNameAwareDdlBuilderInterface
{
    public function getObjectName(): string;
}
