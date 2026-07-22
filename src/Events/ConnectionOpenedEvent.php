<?php

declare(strict_types=1);

namespace SQLCraft\Events;

use SQLCraft\Contracts\Connection\ConnectionInterface;

final readonly class ConnectionOpenedEvent extends ObservabilityEvent
{
    public function __construct(
        public string $name,
        public string $driver,
        public ?string $host,
        public ?string $database,
        public float $elapsedMs,
        public ?ConnectionInterface $connection = null,
    ) {}

    /** @return array<string,mixed> */
    public function __serialize(): array { return ['name'=>$this->name,'driver'=>$this->driver,'host'=>$this->host,'database'=>$this->database,'elapsedMs'=>$this->elapsedMs]; }
}
