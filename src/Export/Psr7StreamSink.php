<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;
use SQLCraft\Contracts\Export\SinkInterface;

final class Psr7StreamSink implements SinkInterface
{
    public function __construct(private object $stream)
    {
        if (!method_exists($stream, 'write')) {
            throw new InvalidArgumentException('PSR-7 stream must implement write().');
        }
    }

    #[\Override]
    public function write(string $bytes): void
    {
        $written = $this->call('write', $bytes);
        if (!is_int($written) || $written !== strlen($bytes)) {
            throw new \RuntimeException('Unable to write to the PSR-7 export stream.');
        }
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        $callable = [$this->stream, $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('PSR-7 stream method is not callable.');
        }
        return $callable(...$arguments);
    }

    #[\Override]
    public function flush(): void
    {
        if (method_exists($this->stream, 'flush')) {
            $this->stream->flush();
        }
    }

    #[\Override]
    public function close(): void
    {
    }
}
