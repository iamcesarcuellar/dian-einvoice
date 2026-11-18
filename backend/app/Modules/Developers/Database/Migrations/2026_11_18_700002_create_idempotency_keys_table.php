<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency-Key of POST requests, per company. The unique key reserves it atomically: the first
 * request inserts the row (processing), runs and stores its response for 24 hours (completed); a
 * repetition with the same fingerprint gets that response back without running anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->char('fingerprint', 64)->comment('SHA-256 of method, path and body');
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('status', 20)->comment('processing or completed');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('locked_until')->nullable()->comment('A processing row older than this was abandoned');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
