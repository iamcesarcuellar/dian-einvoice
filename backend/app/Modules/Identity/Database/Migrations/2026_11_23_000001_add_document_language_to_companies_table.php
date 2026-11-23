<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language of a company's documents, en or es: the PDF, the email to the customer and the texts its chain
 * stores for the timeline. Existing companies keep English, the language they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('document_language', 2)->default('en')->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('document_language');
        });
    }
};
