<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholdings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('dian_code', 2)->comment('DIAN withholding type: 05 ReteIVA, 06 ReteFuente, 07 ReteICA');
            $table->string('name', 100);
            $table->decimal('rate', 7, 4)->comment('Percentage');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false)->comment('Company default: can be deactivated, never deleted');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholdings');
    }
};
