<?php
declare(strict_types=1);
namespace SQLCraft\Support;
use SQLCraft\Exceptions\ExtensionConfigurationException;
/** @internal */
final class ExtensionIdentifier
{
    public static function normalize(string $identifier, string $kind = 'identifier'): string
    {
        $value = strtolower(trim($identifier));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F\s]/', $value) === 1) {
            throw new ExtensionConfigurationException(sprintf('Invalid %s identifier: %s.', $kind, $identifier));
        }
        return $value;
    }
}
