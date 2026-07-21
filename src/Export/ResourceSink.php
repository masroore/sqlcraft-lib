<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use RuntimeException;
use SQLCraft\Contracts\Export\SinkInterface;

final class ResourceSink implements SinkInterface
{
    /** @var resource */
    private $resource;

    private bool $closed = false;

    /**
     * PHP has no native resource parameter type; the boundary is validated here.
     *
     * @param mixed $resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('ResourceSink requires an open stream resource.');
        }

        $this->resource = $resource;
    }

    #[\Override]
    public function write(string $bytes): void
    {
        $this->assertOpen();
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($this->resource, substr($bytes, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Unable to write to the export sink.');
            }
            $written += $count;
        }
    }

    #[\Override]
    public function flush(): void
    {
        $this->assertOpen();
        if (fflush($this->resource) === false) {
            throw new RuntimeException('Unable to flush the export sink.');
        }
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $resource = $this->resource;
        if (fclose($resource) === false) {
            throw new RuntimeException('Unable to close the export sink.');
        }
        $this->closed = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Export sink is closed.');
        }
    }
}
