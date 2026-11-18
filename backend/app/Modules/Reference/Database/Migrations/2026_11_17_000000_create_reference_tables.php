<?php

use App\Modules\Reference\Support\Divipola;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('code', 2)->unique()->comment('DANE DIVIPOLA department code');
            $table->string('name', 100);
        });

        Schema::create('municipalities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('code', 5)->unique()->comment('DANE DIVIPOLA municipality code');
            $table->char('department_code', 2)->index();
            $table->string('name', 100)->index();

            $table->foreign('department_code')->references('code')->on('departments');
        });

        // Reference data every environment needs, not demo data.
        Divipola::import();
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('departments');
    }
};
