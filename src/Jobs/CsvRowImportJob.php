<?php

namespace Pvtl\CsvImporter\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Pvtl\CsvImporter\CsvImportData;

/**
 * Job responsible for handling the import of a single CSV row.
 */
class CsvRowImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * A function that handles CSV row import.
     */
    public $callbackHandler;

    /**
     * A function that handles CSV row error.
     */
    public $callbackValidationErrorHandler;

    /**
     * A function that handles the completion of the import
     */
    public $callbackImportCompletionHandler;

    /**
     * Import Data.
     */
    public CsvImportData $data;

    /**
     * Create a new job instance.
     */
    public function __construct(
        callable|string|array $callbackHandler,
        callable|string|array $callbackValidationErrorHandler,
        callable|string|array $callbackImportCompletionHandler,
        CsvImportData $data
    ) {
        $this->callbackHandler = $callbackHandler;
        $this->callbackValidationErrorHandler = $callbackValidationErrorHandler;
        $this->callbackImportCompletionHandler = $callbackImportCompletionHandler;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->data->row = Validator::make($this->data->row, $this->data->columns)->validate();
            call_user_func($this->callbackHandler, $this->data);
        } catch (ValidationException $exception) {
            call_user_func($this->callbackValidationErrorHandler, $exception, $this->data);
        }

        if ($this->data->is_last_row) {
            call_user_func($this->callbackImportCompletionHandler, $this->data);
        }
    }
}
