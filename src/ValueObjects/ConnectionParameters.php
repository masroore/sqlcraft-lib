<?php

declare(strict_types=1);

namespace SQLCraft\ValueObjects;

use InvalidArgumentException;
use SensitiveParameter;
use SQLCraft\Support\StringUtil;

final readonly class ConnectionParameters
{
    /**
     * @param array<string, scalar|null> $ssl
     * @param array<string, scalar|null> $extras
     */
    public function __construct(
        public ?string $host = null,
        public ?int $port = null,
        public ?string $socket = null,
        public ?string $database = null,
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public ?string $charset = null,
        public array $ssl = [],
        public array $extras = [],
    ) {
        if ($host !== null && (StringUtil::isBlank($host) || StringUtil::containsNullByte($host))) {
            throw new InvalidArgumentException('Connection host must not be blank or contain null bytes.');
        }

        if ($socket !== null && (StringUtil::isBlank($socket) || StringUtil::containsNullByte($socket))) {
            throw new InvalidArgumentException('Connection socket must not be blank or contain null bytes.');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Connection port must be between 1 and 65535.');
        }
    }
}
