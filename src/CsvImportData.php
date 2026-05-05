<?php

namespace Pvtl\CsvImporter;

class CsvImportData
{
    public function __construct(
        public array $row,
        public array $columns,
        public string $import_id,
        public int $row_number,
        public bool $is_last_row = false,
        public array $options = [],
    ) {}

    public static function from(array $data): self
    {
        return new self(...$data);
    }
}
