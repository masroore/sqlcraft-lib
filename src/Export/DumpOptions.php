<?php

declare(strict_types=1);

namespace SQLCraft\Export;

use InvalidArgumentException;

final readonly class DumpOptions
{
    public function __construct(
        public string $format,
        public DumpScope $scope,
        public DatabaseSectionStyle $databaseStyle = DatabaseSectionStyle::None,
        public TableSectionStyle $tableStyle = TableSectionStyle::DropCreate,
        public DataStyle $dataStyle = DataStyle::Insert,
        public bool $includeAutoIncrement = true,
        public bool $includeTriggers = false,
        public bool $includeRoutines = false,
        public bool $includeEvents = false,
        public bool $includeUserTypes = false,
        public int $batchSize = 100,
        public ?string $csvSeparator = null,
        public string $nullRepresentation = '\\N',
        public ?JsonExportOptions $jsonOptions = null,
        public ?XmlExportOptions $xmlOptions = null,
        public ?XlsxExportOptions $xlsxOptions = null,
        public ?HtmlExportOptions $htmlOptions = null,
    ) {
        if (trim($format) === '') {
            throw new InvalidArgumentException('Export format cannot be empty.');
        }
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Export batch size must be >= 1.');
        }
        if ($csvSeparator === '') {
            throw new InvalidArgumentException('CSV separator cannot be empty.');
        }
        if ($nullRepresentation === '') {
            throw new InvalidArgumentException('NULL representation cannot be empty.');
        }
    }
}
