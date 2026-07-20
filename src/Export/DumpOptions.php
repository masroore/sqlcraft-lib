<?php

declare(strict_types=1);

namespace SQLCraft\Export;

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
    ) {
    }
}
