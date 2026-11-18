<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('type', 10)->comment('product or service');
            $table->string('unit_code', 3)->comment('DIAN unit of measure (UN/ECE code)');
            $table->decimal('unit_price', 18, 6);
            $table->foreignUlid('tax_id')->nullable()->comment('Null means excluded: no tax')->constrained('taxes')->restrictOnDelete();
            $table->string('standard_scheme', 20)->nullable()->comment('Product identification standard, such as UNSPSC');
            $table->string('standard_code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
