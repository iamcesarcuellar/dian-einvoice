<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('legal_name', 200);
            $table->string('identification_number', 15)->unique()->comment('NIT without the check digit');
            $table->unsignedTinyInteger('check_digit')->comment('DIAN modulo 11 check digit, computed by the API');
            $table->char('organization_type', 1)->nullable()->comment('1 legal entity, 2 natural person');
            $table->string('tax_scheme', 2)->nullable()->comment('DIAN tax scheme: 01, 04, ZA or ZZ');
            $table->json('fiscal_responsibilities')->nullable()->comment('DIAN fiscal responsibility codes');
            $table->string('registration_number', 20)->nullable()->comment('Commercial registration number');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 200)->nullable();
            $table->char('municipality_code', 5)->nullable()->comment('DANE DIVIPOLA code; the country is always CO');
            $table->string('postal_code', 6)->nullable();
            $table->timestamps();

            $table->foreign('municipality_code')->references('code')->on('municipalities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
