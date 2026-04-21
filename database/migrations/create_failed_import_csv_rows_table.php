<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('failed_import_csv_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_id');
            $table->string('importable');
            $table->json('row');
            $table->text('validation_error');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_import_csv_rows');
    }
};
