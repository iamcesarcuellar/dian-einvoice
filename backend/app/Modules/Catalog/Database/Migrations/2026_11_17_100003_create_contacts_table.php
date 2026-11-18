<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->char('organization_type', 1)->comment('1 legal entity, 2 natural person');
            $table->string('identification_type', 2)->comment('DIAN identity document type');
            $table->string('identification_number', 20);
            $table->unsignedTinyInteger('check_digit')->nullable()->comment('Only for NIT; computed by the API');
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('tax_scheme', 2)->comment('DIAN tax scheme: 01, 04, ZA or ZZ');
            $table->json('fiscal_responsibilities')->comment('DIAN fiscal responsibility codes');
            $table->string('email')->nullable()->comment('Where documents are delivered; required except for the final consumer');
            $table->string('phone', 30)->nullable();
            $table->string('address', 200)->nullable();
            $table->char('country_code', 2)->default('CO')->comment('ISO 3166-1 alpha-2');
            $table->char('municipality_code', 5)->nullable()->comment('DANE DIVIPOLA code; only for contacts in Colombia');
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false)->comment('The final consumer: cannot be edited or deleted');
            $table->timestamps();

            $table->unique(['company_id', 'identification_type', 'identification_number'], 'contacts_company_identification_unique');
            $table->index(['company_id', 'legal_name']);
            $table->foreign('municipality_code')->references('code')->on('municipalities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
