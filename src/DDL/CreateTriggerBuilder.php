<?php

declare(strict_types=1);

namespace SQLCraft\DDL;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\DDL\DdlBuilderInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final readonly class CreateTriggerBuilder implements DdlBuilderInterface
{
    public function __construct(
        public QualifiedName $name,
        public QualifiedName $table,
        public TriggerTiming $timing,
        public TriggerEvent $event,
        public string $body,
        public ?string $definer = null,
        public string $forEach = 'ROW',
    ) {
    }

    /** @return list<string> */
    #[\Override]
    public function toSql(DdlDialectInterface $dialect): array
    {
        return [$dialect->renderCreateTriggerStatement($this->name, $this->table, $this->timing, $this->event, $this->body, $this->definer, $this->forEach)];
    }

    #[\Override]
    public function execute(ConnectionInterface $connection): void
    {
        foreach ($this->toSql($connection->getPlatform()) as $sql) {
            $connection->execute($sql);
        }
    }
}
