<?php

namespace Pvtl\CsvImporter;

use Illuminate\Support\ServiceProvider;
use Pvtl\CsvImporter\Console\Commands\PublishCommand;
use Pvtl\CsvImporter\Console\Commands\PublishExampleCommand;

class CsvImporterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishCommand::class,
                PublishExampleCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../database/migrations/create_failed_import_csv_rows_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_failed_import_csv_rows_table.php'),
            ], 'pvtl-csv-importer-migrations');

            $this->publishes([
                __DIR__.'/../stubs/ExampleCsvImporterService.stub' => app_path('Services/CSV/ExampleCsvImporterService.php'),
            ], 'pvtl-csv-importer-example');
        }
    }
}
