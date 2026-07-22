<?php

declare(strict_types=1);

namespace SQLCraft\Capabilities;

use SQLCraft\Exceptions\CapabilityException;

final class CapabilityNotSupportedException extends CapabilityException
{
    public function __construct(
        public readonly Capability|ExtendedCapability $capability,
        public readonly string $platform,
        public readonly string $version = '',
    ) {
        $capabilityName = $capability instanceof Capability
            ? $capability->value
            : $capability->name;
        $context = $platform === ''
            ? $capabilityName
            : sprintf('%s on %s%s', $capabilityName, $platform, $version === '' ? '' : ' ' . $version);

        parent::__construct(sprintf('Capability not supported: %s.', $context));
    }

    public static function for(
        Capability|ExtendedCapability $capability,
        string $platform = '',
        string $version = '',
    ): self {
        return new self($capability, $platform, $version);
    }
}
