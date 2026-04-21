# CSV Importer

A Laravel package for background CSV imports via Laravel queue jobs. Each row in the CSV is dispatched as an individual queued job, validated against your defined rules, and handed off to your own handler logic.

## Requirements

- PHP 8.3+
- Laravel 12+
- A configured [queue driver](https://laravel.com/docs/queues#driver-prerequisites) (database, Redis, SQS, etc.)

## Installation

Add the repository to your `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/pvtl/csv-importer" }
]
```

Then require the package:

```bash
composer require pvtl/csv-importer
```

Publish and run the migration to create the `failed_import_csv_rows` table:

```bash
php artisan pvtl-csv-importer:publish
php artisan migrate
```

Optionally publish an example service to use as a starting point:

```bash
php artisan pvtl-csv-importer:example
# Publishes to: app/Services/CSV/ExampleCsvImporterService.php
```

---

## How It Works

1. You extend `CsvImporterService` and define your columns, validation rules, and handler logic.
2. You instantiate your service class with a file stream resource. The import starts immediately.
3. One `CsvRowImportJob` is dispatched to the queue **per row**.
4. Each job calls `transformRow()` to normalise the raw row, then validates it against your `$columns` rules.
5. On success `handleRow()` is called; on failure `handleValidationError()` is called.
6. When the last row's job runs, `handleImportCompletion()` is called.

---

## 1. Creating an Importer Service

Extend `CsvImporterService` and implement the two required abstract methods. Define your columns and their validation rules as a class property.

```php
<?php

namespace App\Services\CSV;

use App\Models\User;
use App\Notifications\ImportCompletedNotification;
use Pvtl\CsvImporter\CsvImportData;
use Pvtl\CsvImporter\CsvImporterService;
use Pvtl\CsvImporter\Models\FailedImportCsvRow;

class UserImporterService extends CsvImporterService
{
    /**
     * A human-readable label for this importer.
     * Also used as the filename slug for the downloadable example CSV.
     */
    public const string IMPORT_LABEL = 'User Import';

    /**
     * The field delimiter used when parsing the CSV file.
     * Defaults to comma. Override for other formats:
     *   "\t" → TSV (tab-separated)
     *   ";"  → semicolon (common in European Excel exports)
     *   "|"  → pipe-delimited
     */
    // protected string $delimiter = ',';

    /**
     * Define which CSV columns to accept and their Laravel validation rules.
     * Any CSV column NOT listed here is silently ignored.
     *
     * @see https://laravel.com/docs/validation#available-validation-rules
     */
    protected array $columns = [
        'Name'  => ['required', 'string', 'max:255'],
        'Email' => ['required', 'email', 'max:255'],
        'Phone' => ['nullable', 'string', 'max:50'],
        'Role'  => ['required', 'in:admin,editor,viewer'],
    ];

    /**
     * Sample data used to generate the downloadable example CSV file.
     * Keys must match the column names defined in $columns above.
     */
    protected static array $exampleCsv = [
        [
            'Name'  => 'Jane Smith',
            'Email' => 'jane.smith@example.com',
            'Phone' => '+61412345678',
            'Role'  => 'editor',
        ],
    ];

    /**
     * Called once per row via the queue, after validation passes.
     *
     * $data->row       - the validated CSV row as an associative array
     * $data->options   - any extra context passed at import time
     * $data->import_id - the UUID for this import run
     */
    public static function handleRow(CsvImportData $data): void
    {
        $row = $data->row;

        User::create([
            'name'     => $row['Name'],
            'email'    => $row['Email'],
            'phone'    => $row['Phone'] ?? null,
            'role'     => $row['Role'],
            'password' => bcrypt('temporary-password'),
        ]);
    }

    /**
     * Called once, when the last row's job completes.
     *
     * Use this to send notifications, update status records,
     * or trigger any post-import side effects.
     */
    public static function handleImportCompletion(CsvImportData $data): void
    {
        $failedCount = FailedImportCsvRow::where('import_id', $data->import_id)->count();

        $user = User::find($data->options['user_id'] ?? null);
        $user?->notify(new ImportCompletedNotification(self::IMPORT_LABEL, $failedCount));
    }
}
```

---

## 2. Starting an Import

The importer accepts a file stream resource. Pass one to your service class and the import is triggered immediately inside the constructor.

```php
use App\Services\CSV\UserImporterService;
use Illuminate\Support\Facades\Storage;

// Typically inside a controller action
public function import(Request $request): RedirectResponse
{
    $stream = Storage::readStream($request->file('csv')->store('imports/csv'));

    new UserImporterService($stream);

    return back()->with('success', 'Import queued successfully.');
}
```

### Passing Options

You can pass an associative array as the second argument. These options are forwarded to every row handler job and are accessible via `$data->options`. Use this to pass request context — such as the authenticated user or tenant ID — into your queue jobs without relying on session or request state.

```php
$stream = Storage::readStream($request->file('csv')->store('imports/csv'));

new UserImporterService($stream, [
    'user_id' => auth()->id(),
    'team_id' => auth()->user()->team_id,
]);
```

```php
// Access options inside handleRow()
public static function handleRow(CsvImportData $data): void
{
    User::create([
        'name'    => $data->row['Name'],
        'email'   => $data->row['Email'],
        'team_id' => $data->options['team_id'],
    ]);
}
```

---

## 3. Overridable Methods

Beyond the two abstract methods you **must** implement, there are additional methods with default behaviour that you can override in your subclass.

### `transformRow(array $row, CsvImportData $data): array` *(overridable)*

Called before validation runs, once per row. Use it to normalise, clean, or reshape cell values. The `$row` array contains **all CSV columns** at this point — it has not yet been filtered to `$columns`. Must return the (modified) row array.

```php
public static function transformRow(array $row, CsvImportData $data): array
{
    $row['Email'] = strtolower(trim($row['Email'] ?? ''));
    $row['Phone'] = preg_replace('/\D/', '', $row['Phone'] ?? '');
    $row['Name']  = ucwords(strtolower($row['Name'] ?? ''));

    return $row;
}
```

### `handleRow(CsvImportData $data): void` *(abstract — must implement)*

Called on the queue worker for each CSV row that passes validation. `$data->row` contains only the columns listed in your `$columns` property; all other CSV columns are stripped.

```php
public static function handleRow(CsvImportData $data): void
{
    $row    = $data->row;
    $teamId = $data->options['team_id'] ?? null;

    User::create([
        'name'    => $row['Name'],
        'email'   => $row['Email'],
        'team_id' => $teamId,
    ]);
}
```

### `handleImportCompletion(CsvImportData $data): void` *(abstract — must implement)*

Called exactly once, by the job processing the last row, after `handleRow()` or `handleValidationError()` finishes for that row. Use `$data->import_id` to query any failed rows recorded during the import.

```php
public static function handleImportCompletion(CsvImportData $data): void
{
    $failedRows = FailedImportCsvRow::where('import_id', $data->import_id)->get();

    $user = User::find($data->options['user_id'] ?? null);
    $user?->notify(new ImportCompletedNotification(
        self::IMPORT_LABEL,
        $failedRows->count()
    ));
}
```

> **Note on `is_last_row`:** The library determines the last row by comparing the file stream position to the file size at dispatch time. Because jobs are not guaranteed to execute in dispatch order, `handleImportCompletion()` could fire before other row jobs have finished processing. Design your completion logic accordingly — for example, by counting expected vs. processed rows rather than assuming sequential execution.

### `handleValidationError(ValidationException $exception, CsvImportData $data): void` *(overridable)*

The default implementation writes a `FailedImportCsvRow` record to the database. Override this to add custom logging, skip DB persistence, or trigger alerts:

```php
use Illuminate\Validation\ValidationException;
use Pvtl\CsvImporter\CsvImportData;

public static function handleValidationError(ValidationException $exception, CsvImportData $data): void
{
    // Call the parent to still persist the failure record
    parent::handleValidationError($exception, $data);

    // Then add your own behaviour
    \Log::warning('CSV row failed validation', [
        'import_id' => $data->import_id,
        'row'       => $data->row,
        'error'     => $exception->getMessage(),
    ]);
}
```

Or replace the default entirely to skip the database record:

```php
public static function handleValidationError(ValidationException $exception, CsvImportData $data): void
{
    // Custom behaviour only — no DB record written
    \Log::channel('imports')->error($exception->getMessage(), ['row' => $data->row]);
}
```

### `downloadExample(): StreamedResponse` *(overridable)*

Streams a sample CSV file built from `$exampleCsv`. Expose this in a controller to let users download a template:

```php
// In a controller:
public function exampleCsv(): StreamedResponse
{
    return UserImporterService::downloadExample();
    // Streams a file named: "user-import-example.csv"
}
```

Override it if you need custom headers or formatting:

```php
public static function downloadExample(): StreamedResponse
{
    return response()->stream(function () {
        // ...
    }, 200, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename=my-custom-template.csv',
    ]);
}
```

### `getCsvColumns(): array` *(overridable)*

Returns the column names derived from `$exampleCsv`. Override when your runtime columns differ from your example data:

```php
public static function getCsvColumns(): array
{
    return ['Name', 'Email', 'Phone', 'Role'];
}
```

---

## The `CsvImportData` Object

Every callback receives a `CsvImportData` instance with the following properties:

| Property | Type | Description |
|---|---|---|
| `$row` | `array` | The validated CSV row, keyed by column name. Only columns defined in `$columns` are present. |
| `$columns` | `array` | The column definitions (name => rules) from your service class. |
| `$import_id` | `string` | A UUID unique to this import run. Use it to group `FailedImportCsvRow` records. |
| `$is_last_row` | `bool` | `true` if this is the final row in the CSV file. |
| `$row_number` | `int` | 1-indexed position of the row in the file, excluding the header row. |
| `$options` | `array` | The options array passed to the constructor. |

---

## Querying Failed Rows

Rows that fail validation are recorded in the `failed_import_csv_rows` table via the `FailedImportCsvRow` model.

```php
use Pvtl\CsvImporter\Models\FailedImportCsvRow;

// Inside handleImportCompletion():
$failed = FailedImportCsvRow::where('import_id', $data->import_id)->get();

foreach ($failed as $record) {
    echo $record->validation_error;
    dump($record->row); // the raw CSV row data
}
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `php artisan pvtl-csv-importer:publish` | Publishes the database migration to `database/migrations/` |
| `php artisan pvtl-csv-importer:example` | Publishes an example service to `app/Services/CSV/ExampleCsvImporterService.php` |
