<?php

namespace Pvtl\CsvImporter\Models;

use Illuminate\Database\Eloquent\Model;

class FailedImportCsvRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'row' => 'array',
    ];
}
