<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Export;

interface SinkInterface
{
    public function write(string $bytes): void;

    public function flush(): void;

    public function close(): void;
}
