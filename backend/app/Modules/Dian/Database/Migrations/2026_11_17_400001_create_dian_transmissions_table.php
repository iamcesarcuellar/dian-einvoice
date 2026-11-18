<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call to the DIAN web service (here, always the local simulator): one row per operation of
 * each attempt, written when the call starts and completed when it ends. A row that never ends
 * marks a worker that stopped during the call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_transmissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('run')->comment('Pass of the chain of the document');
            $table->unsignedTinyInteger('attempt');
            $table->string('operation', 20)->comment('SendBillSync or GetStatus');
            $table->string('gateway', 20)->comment('simulator');
            $table->string('endpoint');
            $table->string('zip_file_name', 40)->nullable();
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 20)->nullable()->comment('responded, found, not_found, timeout, no_response, refused, server_error, invalid_response, client_error or abandoned');
            $table->boolean('may_have_arrived')->default(false)->comment('Sent without an answer: the next attempt asks GetStatus first');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status_code', 4)->nullable();
            $table->string('status_description', 500)->nullable();
            $table->string('status_message', 1000)->nullable();
            $table->boolean('is_valid')->nullable();
            $table->json('messages')->nullable()->comment('Rules failed or noted: rule, kind and message');
            $table->string('response_path')->nullable()->comment('ApplicationResponse received, in the private document storage');
            $table->string('error', 1000)->nullable();

            $table->index(['document_id', 'run', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_transmissions');
    }
};
