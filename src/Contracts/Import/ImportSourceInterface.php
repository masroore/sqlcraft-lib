<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Import;

interface ImportSourceInterface
{
    public function openStream(): mixed;

    public function getEstimatedSize(): ?int;
}
