<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\DDL;

use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

interface TriggerDefinitionInterface
{
    public function getName(): QualifiedName;
    public function getTable(): QualifiedName;
    public function getTiming(): TriggerTiming;
    public function getEvent(): TriggerEvent;
    public function getBody(): string;
    public function getDefiner(): ?string;
    public function getForEach(): string;
}
