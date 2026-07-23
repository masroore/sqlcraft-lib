<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Exceptions\DuplicateRegistrationException;
use SQLCraft\Exceptions\ExtensionConfigurationException;
use SQLCraft\Exceptions\RegistrationNotFoundException;
use SQLCraft\Support\ExtensionIdentifier;

final class FormatRegistry
{
    /** @var array<string, \Closure(ConnectionInterface): FormatWriterInterface> */
    private array $writers = [];

    /** @var array<string, \Closure(): FormatReaderInterface> */
    private array $readers = [];

    private ?ConnectionInterface $connection;

    /**
     * @param  iterable<mixed>  $writers
     * @param  iterable<mixed>  $readers
     * @param  ConnectionInterface|iterable<mixed>|null  $connection
     */
    public function __construct(
        ConnectionInterface|iterable|null $connection = null,
        iterable $writers = [],
        iterable $readers = [],
    ) {
        $writerList = [];
        $readerList = [];
        if ($connection instanceof ConnectionInterface || $connection === null) {
            $this->connection = $connection;
            foreach ($writers as $writer) {
                if (! $writer instanceof FormatWriterInterface) {
                    throw new \InvalidArgumentException('Writer registration must implement FormatWriterInterface.');
                }
                $writerList[] = $writer;
            }
            foreach ($readers as $reader) {
                if (! $reader instanceof FormatReaderInterface) {
                    throw new \InvalidArgumentException('Reader registration must implement FormatReaderInterface.');
                }
                $readerList[] = $reader;
            }
        } else {
            $this->connection = null;
            /** @var iterable<mixed> $legacyWriters */
            $legacyWriters = $connection;
            foreach ($legacyWriters as $candidate) {
                if (! $candidate instanceof FormatWriterInterface) {
                    throw new \InvalidArgumentException('Writer registration must implement FormatWriterInterface.');
                }
                $writerList[] = $candidate;
            }
            /** @var iterable<mixed> $legacyReaders */
            $legacyReaders = $writers;
            /** @psalm-suppress MixedAssignment */
            foreach ($legacyReaders as $candidate) {
                if (! $candidate instanceof FormatReaderInterface) {
                    throw new \InvalidArgumentException('Reader registration must implement FormatReaderInterface.');
                }
                $readerList[] = $candidate;
            }
        }
        foreach ($writerList as $writer) {
            $this->registerWriter($writer);
        }
        foreach ($readerList as $reader) {
            $this->registerReader($reader);
        }
    }

    public function registerWriter(FormatWriterInterface $writer): void
    {
        $name = ExtensionIdentifier::normalize($writer->getFormatName(), 'writer');
        if (isset($this->writers[$name])) {
            throw new DuplicateRegistrationException("Writer already registered: $name.");
        }
        $this->writers[$name] = static fn (ConnectionInterface $connection): FormatWriterInterface => $writer;
    }

    /** @param \Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function registerWriterFactory(string $format, \Closure $factory): void
    {
        $name = ExtensionIdentifier::normalize($format, 'writer');
        if (isset($this->writers[$name])) {
            throw new DuplicateRegistrationException("Writer already registered: $name.");
        }
        $this->writers[$name] = $factory;
    }

    /** @param \Closure(ConnectionInterface): FormatWriterInterface $factory */
    public function replaceWriterFactory(string $format, \Closure $factory): void
    {
        $name = ExtensionIdentifier::normalize($format, 'writer');
        if (! isset($this->writers[$name])) {
            throw new RegistrationNotFoundException("Writer is not registered: $name.");
        }
        $this->writers[$name] = $factory;
    }

    public function registerReader(FormatReaderInterface $reader): void
    {
        $name = ExtensionIdentifier::normalize($reader->getFormatName(), 'reader');
        if (isset($this->readers[$name])) {
            throw new DuplicateRegistrationException("Reader already registered: $name.");
        }
        $this->readers[$name] = static fn (): FormatReaderInterface => $reader;
    }

    /** @param \Closure(): FormatReaderInterface $factory */
    public function registerReaderFactory(string $format, \Closure $factory): void
    {
        $name = ExtensionIdentifier::normalize($format, 'reader');
        if (isset($this->readers[$name])) {
            throw new DuplicateRegistrationException("Reader already registered: $name.");
        }
        $this->readers[$name] = $factory;
    }

    /** @param \Closure(): FormatReaderInterface $factory */
    public function replaceReaderFactory(string $format, \Closure $factory): void
    {
        $name = ExtensionIdentifier::normalize($format, 'reader');
        if (! isset($this->readers[$name])) {
            throw new RegistrationNotFoundException("Reader is not registered: $name.");
        }
        $this->readers[$name] = $factory;
    }

    public function getWriter(string $format): FormatWriterInterface
    {
        $name = ExtensionIdentifier::normalize($format, 'writer');
        $factory = $this->writers[$name] ?? throw new \InvalidArgumentException("Unsupported export format: $name.");
        $connection = $this->connection ?? throw new ExtensionConfigurationException('A connection is required to resolve a format writer.');

        return $this->validatedWriter($factory($connection), $name);
    }

    public function getReader(string $format): FormatReaderInterface
    {
        $name = ExtensionIdentifier::normalize($format, 'reader');
        $factory = $this->readers[$name] ?? throw new \InvalidArgumentException("Unsupported import format: $name.");

        return $this->validatedReader($factory(), $name);
    }

    private function validatedWriter(mixed $writer, string $name): FormatWriterInterface
    {
        if (! $writer instanceof FormatWriterInterface || $writer->getFormatName() !== $name) {
            throw new ExtensionConfigurationException("Writer factory returned an invalid adapter for: $name.");
        }

        return $writer;
    }

    private function validatedReader(mixed $reader, string $name): FormatReaderInterface
    {
        if (! $reader instanceof FormatReaderInterface || $reader->getFormatName() !== $name) {
            throw new ExtensionConfigurationException("Reader factory returned an invalid adapter for: $name.");
        }

        return $reader;
    }

    /** @return list<string> */
    public function getSupportedWriteFormats(): array
    {
        return array_keys($this->writers);
    }

    /** @return list<string> */
    public function getSupportedReadFormats(): array
    {
        return array_keys($this->readers);
    }
}
