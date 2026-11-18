<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to email the zip of a document to its customer, written before the mailer is called
 * and completed when it returns, so an attempt whose worker stopped is never invisible. In this
 * project the mail server is always the local development mailbox: nothing leaves the machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('run')->comment('Delivery run of the document');
            $table->unsignedTinyInteger('attempt')->comment('1 to the maximum of attempts, within the run');
            $table->string('recipient');
            $table->string('subject', 300)->comment('NIT;name;number;type;trade name (annex 1.9, section 9.1)');
            $table->string('package_file_name', 60);
            $table->char('package_sha256', 64);
            $table->string('mailer', 20)->comment('Laravel mailer; smtp to the local mailbox here');
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 20)->nullable()->comment('sent, failed (transient, retried), rejected (final) or abandoned');
            $table->string('message_id')->nullable()->comment('Message-ID of the email the mail server accepted');
            $table->string('error', 500)->nullable();

            $table->index(['document_id', 'run', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
