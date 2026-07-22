<?php
declare(strict_types=1);
namespace SQLCraft\Export;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Export\FormatWriterInterface;
use SQLCraft\Contracts\Import\FormatReaderInterface;
use SQLCraft\Exceptions\ExtensionConfigurationException;
use SQLCraft\Exceptions\DuplicateRegistrationException;
use SQLCraft\Support\ExtensionIdentifier;
final class FormatRegistry
{
    /** @var array<string, \Closure(ConnectionInterface): FormatWriterInterface> */ private array $writers=[];
    /** @var array<string, \Closure(): FormatReaderInterface> */ private array $readers=[];
    private ?ConnectionInterface $connection;
    public function __construct(mixed $connection = null, iterable $writers = [], iterable $readers = [])
    {
        if (is_iterable($connection) && ! $connection instanceof ConnectionInterface) { $readers=$writers; $writers=$connection; $connection=null; }
        $this->connection=$connection instanceof ConnectionInterface ? $connection : null;
        foreach ($writers as $writer) { $this->registerWriter($writer); } foreach ($readers as $reader) { $this->registerReader($reader); }
    }
    public function registerWriter(FormatWriterInterface $writer): void { $name=ExtensionIdentifier::normalize($writer->getFormatName(),'writer'); if(isset($this->writers[$name])) throw new DuplicateRegistrationException("Writer already registered: $name."); $this->writers[$name]=fn(ConnectionInterface $connection): FormatWriterInterface=>$writer; }
    public function registerWriterFactory(string $format, \Closure $factory): void { $name=ExtensionIdentifier::normalize($format,'writer'); if(isset($this->writers[$name])) throw new DuplicateRegistrationException("Writer already registered: $name."); $this->writers[$name]=$factory; }
    public function replaceWriterFactory(string $format, \Closure $factory): void { $name=ExtensionIdentifier::normalize($format,'writer'); if(!isset($this->writers[$name])) throw new \SQLCraft\Exceptions\RegistrationNotFoundException("Writer is not registered: $name."); $this->writers[$name]=$factory; }
    public function registerReader(FormatReaderInterface $reader): void { $name=ExtensionIdentifier::normalize($reader->getFormatName(),'reader'); if(isset($this->readers[$name])) throw new DuplicateRegistrationException("Reader already registered: $name."); $this->readers[$name]=fn(): FormatReaderInterface=>$reader; }
    public function registerReaderFactory(string $format, \Closure $factory): void { $name=ExtensionIdentifier::normalize($format,'reader'); if(isset($this->readers[$name])) throw new DuplicateRegistrationException("Reader already registered: $name."); $this->readers[$name]=$factory; }
    public function replaceReaderFactory(string $format, \Closure $factory): void { $name=ExtensionIdentifier::normalize($format,'reader'); if(!isset($this->readers[$name])) throw new \SQLCraft\Exceptions\RegistrationNotFoundException("Reader is not registered: $name."); $this->readers[$name]=$factory; }
    public function getWriter(string $format): FormatWriterInterface { $name=ExtensionIdentifier::normalize($format,'writer'); $factory=$this->writers[$name]??throw new \InvalidArgumentException("Unsupported export format: $name."); $writer=$factory($this->connection ?? throw new ExtensionConfigurationException('A connection is required to resolve a format writer.')); if(!$writer instanceof FormatWriterInterface || $writer->getFormatName()!==$name) throw new ExtensionConfigurationException("Writer factory returned an invalid adapter for: $name."); return $writer; }
    public function getReader(string $format): FormatReaderInterface { $name=ExtensionIdentifier::normalize($format,'reader'); $factory=$this->readers[$name]??throw new \InvalidArgumentException("Unsupported import format: $name."); $reader=$factory(); if(!$reader instanceof FormatReaderInterface || $reader->getFormatName()!==$name) throw new ExtensionConfigurationException("Reader factory returned an invalid adapter for: $name."); return $reader; }
    public function getSupportedWriteFormats(): array { return array_keys($this->writers); }
    public function getSupportedReadFormats(): array { return array_keys($this->readers); }
}
