<?php

declare(strict_types=1);

namespace SQLCraft\DTO;

use SQLCraft\ValueObjects\ServerVersion;

final readonly class ServerInfo
{
    public function __construct(
        public ServerVersion $version,
        public string $platformName,
        public ?string $flavor,
        public ?string $dataDirectory,
        public ?string $timezone,
        public ?string $charset,
        public ?string $collation,
    ) {}
}
