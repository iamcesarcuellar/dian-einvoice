<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20)->comment('invoice, credit_note or debit_note');
            $table->string('prefix', 4)->nullable();
            $table->unsignedBigInteger('from_number');
            $table->unsignedBigInteger('to_number');
            $table->unsignedBigInteger('next_number')->comment('Next consecutive; above to_number once exhausted');
            $table->string('resolution_number', 30)->nullable()->comment('DIAN resolution; required for invoices');
            $table->date('resolution_date')->nullable()->comment('Date of the DIAN resolution; required for invoices');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('technical_key')->nullable()->comment('Encrypted with the app key; required for invoices');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            // Holds the document type only while the row is the default, so the unique index below
            // allows a single default numbering per document type and company.
            $table->string('default_for', 20)->nullable()->storedAs('if(is_default, document_type, null)');
            $table->timestamps();

            $table->unique(['company_id', 'default_for']);
            $table->index(['company_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_templates');
    }
};
