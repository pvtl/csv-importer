<?php

namespace Pvtl\CsvImporter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishExampleCommand extends Command
{
    protected $signature = 'pvtl-csv-importer:example';

    protected $description = 'Publish an example CSV importer service to app/Services/CSV/';

    public function handle(): void
    {
        $source = __DIR__.'/../../../stubs/ExampleCsvImporterService.stub';
        $destination = app_path('Services/CSV/ExampleCsvImporterService.php');

        if (File::exists($destination)) {
            if (! $this->confirm('ExampleCsvImporterService.php already exists. Overwrite?', false)) {
                $this->info('Example publish cancelled.');

                return;
            }
        }

        File::ensureDirectoryExists(app_path('Services/CSV'));
        File::copy($source, $destination);

        $this->info('Example importer published successfully.');
        $this->line('  <fg=green>→</> app/Services/CSV/ExampleCsvImporterService.php');
        $this->newLine();
        $this->line('Extend <fg=yellow>Pvtl\CsvImporter\CsvImporterService</> and implement <fg=yellow>handleRow()</> and <fg=yellow>handleImportCompletion()</>.');
    }
}
