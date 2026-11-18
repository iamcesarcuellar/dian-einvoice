<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local key store: one encrypted PKCS#12 bundle and its password per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_store_entries', function (Blueprint $table) {
            $table->ulid('id')->primary()->comment('The reference handed to the owner of the key');
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->text('payload')->comment('Encrypted with the app key: PKCS#12 bundle and its password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_store_entries');
    }
};
