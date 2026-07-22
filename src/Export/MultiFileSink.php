<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use Closure;
use InvalidArgumentException;
use SQLCraft\Contracts\Export\SinkInterface;

final class MultiFileSink
{
    private Closure $namer;

    private Closure $factory;

    /** @var array<string, SinkInterface> */
    private array $sinks = [];

    public function __construct(string $directory, ?callable $naming = null, ?callable $factory = null)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Unable to create export directory: %s.', $directory));
        }
        $this->namer = $naming === null
            ? static fn (string $table): string => $table.'.csv'
            : Closure::fromCallable($naming);
        $this->factory = $factory === null
            ? static function (string $path, string $table): SinkInterface {
                $resource = fopen($path, 'wb');
                if ($resource === false) {
                    throw new \RuntimeException(sprintf('Unable to open export file: %s.', $path));
                }

                return new ResourceSink($resource);
            }
        : Closure::fromCallable($factory);
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private string $directory;

    public function forTable(string $table): SinkInterface
    {
        if (isset($this->sinks[$table])) {
            return $this->sinks[$table];
        }
        $name = ($this->namer)($table);
        if (! is_string($name) || $name === '' || str_contains($name, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Table naming callback must return a single non-empty filename.');
        }
        $sink = ($this->factory)($this->directory.DIRECTORY_SEPARATOR.$name, $table);
        if (! $sink instanceof SinkInterface) {
            throw new InvalidArgumentException('Multi-file sink factory must return a SinkInterface.');
        }
        $this->sinks[$table] = $sink;

        return $sink;
    }

    public function sinkFor(string $table): SinkInterface
    {
        return $this->forTable($table);
    }

    public function close(): void
    {
        foreach ($this->sinks as $sink) {
            $sink->close();
        }
        $this->sinks = [];
    }
}
