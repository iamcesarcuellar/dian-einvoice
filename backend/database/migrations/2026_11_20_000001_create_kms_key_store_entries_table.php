<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KMS key store (the emulated cloud and production): one PKCS#12 bundle and its password per row, encrypted
 * with a data key of its own, which only KMS can decrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kms_key_store_entries', function (Blueprint $table) {
            $table->ulid('id')->primary()->comment('The reference handed to the owner of the key');
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('kms_key_id')->comment('ARN of the KMS key that protects the data key');
            $table->text('encrypted_data_key')->comment('The data key encrypted by KMS, base64');
            $table->string('nonce', 24)->comment('AES-256-GCM nonce, base64');
            $table->string('tag', 32)->comment('AES-256-GCM tag, base64');
            $table->mediumText('ciphertext')->comment('PKCS#12 bundle and its password encrypted with the data key, base64');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kms_key_store_entries');
    }
};
