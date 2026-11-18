<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutive of the files each company sends to the DIAN in a calendar year, the "dddddddd" part
 * of the zip and XML names (annex 1.9, 6.5.7 and 6.5.8). It starts again every January 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_file_counters', function (Blueprint $table) {
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number');

            $table->primary(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_file_counters');
    }
};
