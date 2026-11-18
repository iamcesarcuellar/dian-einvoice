<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('environment')->default(2)->comment('DIAN environment; always 2, habilitación (test)');
            $table->string('software_id', 100)->nullable()->comment('Software ID assigned by the DIAN');
            $table->text('software_pin')->nullable()->comment('Encrypted with the app key; write-only');
            $table->string('test_set_id', 100)->nullable()->comment('Test set ID of the habilitación process');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_settings');
    }
};
