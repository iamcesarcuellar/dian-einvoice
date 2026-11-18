<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files delivered to the customer of an accepted document, one per kind: the AttachedDocument, the
 * PDF and the zip with both. The bytes live in the private document storage; each row keeps where,
 * their SHA-256 and their size. A new delivery run replaces the row of the kind it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('attached_document, pdf or package');
            $table->string('file_name', 60)->comment('Name the customer gets, such as ad0990000001000260000001.xml');
            $table->string('mime_type', 60);
            $table->string('path')->comment('Path in the private document storage');
            $table->char('sha256', 64);
            $table->unsignedInteger('size')->comment('Bytes');
            $table->unsignedSmallInteger('pages')->nullable()->comment('Pages of the PDF');
            $table->unsignedSmallInteger('run')->comment('Delivery run that wrote it');
            $table->timestamps();

            $table->unique(['document_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
