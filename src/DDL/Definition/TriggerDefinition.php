<?php

declare(strict_types=1);

namespace SQLCraft\DDL\Definition;

use SQLCraft\Contracts\DDL\TriggerDefinitionInterface;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final readonly class TriggerDefinition implements TriggerDefinitionInterface
{
    public function __construct(
        private QualifiedName $name,
        private QualifiedName $table,
        private TriggerTiming $timing,
        private TriggerEvent $event,
        private string $body,
        private ?string $definer,
        private string $forEach = 'ROW',
    ) {
    }

    #[\Override]
    public function getName(): QualifiedName
    {
        return $this->name;
    }

    #[\Override]
    public function getTable(): QualifiedName
    {
        return $this->table;
    }

    #[\Override]
    public function getTiming(): TriggerTiming
    {
        return $this->timing;
    }

    #[\Override]
    public function getEvent(): TriggerEvent
    {
        return $this->event;
    }

    #[\Override]
    public function getBody(): string
    {
        return $this->body;
    }

    #[\Override]
    public function getDefiner(): ?string
    {
        return $this->definer;
    }

    #[\Override]
    public function getForEach(): string
    {
        return $this->forEach;
    }
}
