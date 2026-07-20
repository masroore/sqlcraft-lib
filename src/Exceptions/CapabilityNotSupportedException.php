<?php

declare(strict_types=1);

namespace SQLCraft\Exceptions;

final class CapabilityNotSupportedException extends CapabilityException
{
    public function __construct(
        string $message,
        public readonly string $capability = '',
        public readonly string $platform = '',
        public readonly string $version = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function for(
        string $capability,
        string $platform = '',
        string $version = '',
    ): self {
        $context = $platform === ''
            ? $capability
            : sprintf('%s on %s%s', $capability, $platform, $version === '' ? '' : ' ' . $version);

        return new self(
            sprintf('Capability not supported: %s.', $context),
            $capability,
            $platform,
            $version,
        );
    }
}
