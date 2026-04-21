<?php

namespace Pvtl\CsvImporter;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pvtl\CsvImporter\Jobs\CsvRowImportJob;
use Pvtl\CsvImporter\Models\FailedImportCsvRow;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abstract service for handling CSV imports. It provides structure and utilities
 * for defining column validation rules, queuing row processing, and handling
 * validation errors. This class is intended to be extended with concrete implementations.
 */
abstract class CsvImporterService
{
    /**
     * Label shown on the frontend to determine the importer
     */
    public const string IMPORT_LABEL = 'not-implemented';

    /**
     * Defines CSV columns and their validation rules.
     * Only defined columns will be imported.
     *
     * Example: [
     *  'name' => ['required', 'max:255'],
     *  'email' => ['required', 'email']
     * ]
     */
    protected array $columns = [];

    /**
     * Array of additional options that can be used in `static::handleRow()` method.
     */
    protected array $options = [];

    /**
     * The unique identifier for this import.
     */
    protected string $importId;

    /**
     * The field delimiter character used when parsing the CSV file.
     * Defaults to comma. Override to support TSV ("\t"), semicolon (";"), etc.
     */
    protected string $delimiter = ',';

    /**
     * The stream recourse of the CSV file.
     *
     * @type resource
     */
    protected $stream;

    /**
     * Defines the CSV data for the example file.
     *
     * Example: [
     *   [
     *      'name' => 'John Smith',
     *      'email' => 'john.smith@email.com'
     *   ]
     * ]
     */
    protected static array $exampleCsv = [];

    public function __construct($stream, array $options = [])
    {
        $this->stream = $stream;
        $this->options = $options;
        $this->importId = Str::uuid();
        $this->import();
    }

    /**
     * Handles the processing of a CSV row.
     *
     * @param  CsvImportData  $data  The data of the CSV row to be processed.
     */
    abstract public static function handleRow(CsvImportData $data): void;

    /**
     * Handles the completion of a CSV import process and sends notifications to users.
     *
     * @param  CsvImportData  $data  The CSV import data containing details about the import process.
     */
    abstract public static function handleImportCompletion(CsvImportData $data): void;

    /**
     * Imports CSV content, processes each row, and dispatches a job for importing rows while notifying
     * about the start of the import process.
     */
    protected function import(): void
    {
        if (! is_resource($this->stream)) {
            throw new \InvalidArgumentException('Expected a valid stream resource');
        }

        $filesize = fstat($this->stream)['size'] ?? 0;

        $header = fgetcsv($this->stream, separator: $this->delimiter);

        if ($header === false) {
            fclose($this->stream);

            return;
        }

        // Strip the UTF-8 BOM that Excel prepends to the first cell when saving as CSV.
        if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr($header[0], 3);
        }

        $header = array_map(fn ($value) => trim($this->toUtf8($value)), $header);

        $rowNumber = 0;

        while (($row = fgetcsv($this->stream, separator: $this->delimiter)) !== false) {
            if (count($row) < count($header)) {
                continue;
            }

            if (count($row) > count($header)) {
                $row = array_slice($row, 0, count($header));
            }

            $rowNumber++;
            $row = array_combine($header, $row);
            $isLast = ftell($this->stream) >= $filesize;

            CsvRowImportJob::dispatch(
                [static::class, 'handleRow'],
                [static::class, 'handleValidationError'],
                [static::class, 'handleImportCompletion'],
                [static::class, 'transformRow'],
                CsvImportData::from([
                    'row' => collect($row)->mapWithKeys(
                        fn ($value, $key) => [trim($key) => trim($this->toUtf8($value))]
                    )->toArray(),
                    'options' => $this->options,
                    'columns' => $this->columns,
                    'import_id' => $this->importId,
                    'is_last_row' => $isLast,
                    'row_number' => $rowNumber,
                ])
            );
        }

        fclose($this->stream);
    }

    /**
     * Ensures a string is valid UTF-8.
     *
     * Handles CSV files saved in Windows-1252 or ISO-8859-1 (the default
     * encoding used by Excel on Windows). Windows-1252 is a superset of
     * ISO-8859-1, so converting from it covers both encodings.
     */
    private function toUtf8(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * Generates and streams a CSV example file for download.
     *
     * @return StreamedResponse The streamed response containing the CSV example file.
     */
    public static function downloadExample(): StreamedResponse
    {
        $filename = Str::slug(static::IMPORT_LABEL).'-example';

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');

            // Write header row
            fputcsv($handle, array_keys(static::$exampleCsv[0]));

            // Write data rows
            foreach (static::$exampleCsv as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$filename}.csv",
        ]);
    }

    /**
     * Transforms a row's values before validation runs.
     *
     * Override this method to normalise, clean, or reshape cell values.
     * Receives the raw row array (all CSV columns, keyed by header name)
     * and the full CsvImportData context (for access to $data->options, etc.).
     * Must return the transformed row array.
     */
    public static function transformRow(array $row, CsvImportData $data): array
    {
        return $row;
    }

    /**
     * Handles the validation error by creating a FailedImportCsvRow record.
     *
     * @param  ValidationException  $exception  The validation exception that occurred.
     * @param  CsvImportData  $data  The CSV import data related to the failed row.
     */
    public static function handleValidationError(ValidationException $exception, CsvImportData $data): void
    {
        FailedImportCsvRow::create([
            'import_id' => $data->import_id,
            'importable' => static::class,
            'row' => $data->row,
            'validation_error' => $exception->getMessage(),
        ]);
    }

    /**
     * Returns the column names of the example CSV.
     *
     * @return array An array of column names extracted from the example CSV structure.
     */
    public static function getCsvColumns(): array
    {
        return array_keys(static::$exampleCsv[0]);
    }
}
