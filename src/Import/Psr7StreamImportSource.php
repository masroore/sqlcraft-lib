<?php

declare(strict_types=1);

namespace SQLCraft\Import;

use InvalidArgumentException;
use SQLCraft\Contracts\Import\ImportSourceInterface;

final readonly class Psr7StreamImportSource implements ImportSourceInterface
{
    public function __construct(private object $stream)
    {
        foreach (['rewind', 'read', 'eof'] as $method) {
            if (! method_exists($stream, $method)) {
                throw new InvalidArgumentException('PSR-7 stream is missing '.$method.'().');
            }
        }
    }

    #[\Override]
    public function openStream(): mixed
    {
        $this->call('rewind');
        $target = fopen('php://temp', 'w+b');
        if ($target === false) {
            throw new \RuntimeException('Unable to allocate import stream.');
        }
        while (! (bool) $this->call('eof')) {
            $chunk = $this->call('read', 8192);
            if (! is_string($chunk) || $chunk === '') {
                break;
            }
            fwrite($target, $chunk);
        }
        rewind($target);

        return $target;
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        $callable = [$this->stream, $method];
        if (! is_callable($callable)) {
            throw new InvalidArgumentException('PSR-7 stream method is not callable.');
        }

        return $callable(...$arguments);
    }

    #[\Override]
    public function getEstimatedSize(): ?int
    {
        if (! method_exists($this->stream, 'getSize')) {
            return null;
        }
        /** @psalm-suppress MixedAssignment */
        $size = $this->call('getSize');

        return is_int($size) ? $size : null;
    }
}
