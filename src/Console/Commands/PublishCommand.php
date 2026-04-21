<?php

namespace Pvtl\CsvImporter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishCommand extends Command
{
    protected $signature = 'pvtl-csv-importer:publish';

    protected $description = 'Publish the pvtl/csv-importer migration file';

    public function handle(): void
    {
        $source = __DIR__.'/../../../database/migrations/create_failed_import_csv_rows_table.php';
        $filename = date('Y_m_d_His').'_create_failed_import_csv_rows_table.php';
        $destination = database_path('migrations/'.$filename);

        if (File::exists($destination)) {
            if (! $this->confirm('Migration already exists. Overwrite?', false)) {
                $this->info('Migration publish cancelled.');

                return;
            }
        }

        File::ensureDirectoryExists(database_path('migrations'));
        File::copy($source, $destination);

        $this->info('Migration published successfully.');
        $this->line("  <fg=green>→</> database/migrations/{$filename}");
        $this->newLine();
        $this->line('Run <fg=yellow>php artisan migrate</> to apply the migration.');
    }
}
