<?php

declare(strict_types=1);

namespace SQLCraft\Support;

final class SecretRedactor
{
    public static function dsn(string $dsn): string
    {
        $redacted = preg_replace('/(mysql|pgsql|sqlsrv|oci):\\/\\/[^:]+:([^@]+)@/i', '$1://[redacted]:[redacted]@', $dsn);
        $redacted = is_string($redacted) ? $redacted : $dsn;
        $redacted = preg_replace('/([;?&](?:password|pwd|pass)=)[^;&]*/i', '$1[redacted]', $redacted);

        return is_string($redacted) ? $redacted : $dsn;
    }
}
