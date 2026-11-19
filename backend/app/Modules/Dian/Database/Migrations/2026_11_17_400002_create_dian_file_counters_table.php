<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutive of the files each company produces in a calendar year, by file prefix: the "dddddddd"
 * part of the names of annex 1.9 (6.5.7 and 6.5.8). fv, nc and nd count the invoices and notes sent
 * to the DIAN and z the zips that carry them (the packages sent, 6.5.8); ad counts the
 * AttachedDocuments delivered to customers (their zip takes the same number). Every counter starts
 * again on January 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_file_counters', function (Blueprint $table) {
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 2)->comment('fv, nc, nd: documents sent to the DIAN; z: the zips sent to the DIAN; ad: AttachedDocument');
            $table->unsignedInteger('last_number');

            $table->primary(['company_id', 'year', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_file_counters');
    }
};
