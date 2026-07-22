<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\TriggerEvent;
use SQLCraft\ValueObjects\TriggerTiming;

final readonly class TriggerMeta
{
    public function __construct(
        public string $name,
        public TriggerTiming $timing,
        public TriggerEvent $event,
        public string $body,
        public ?string $definer,
        public ?string $table,
    ) {}
}
