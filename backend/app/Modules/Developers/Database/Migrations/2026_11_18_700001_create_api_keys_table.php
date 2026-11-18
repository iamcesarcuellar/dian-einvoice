<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys of each company. A key is dei_{public_id}_{secret}: only the SHA-256 of the whole key is
 * stored, and public_id finds the row, so the secret is compared in constant time and never kept.
 * Timelines record the key that acted, next to the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('level', 20)->comment('read_only or read_write');
            $table->char('public_id', 12)->unique()->comment('Visible part of the key, after the prefix');
            $table->char('secret_hash', 64)->comment('SHA-256 of the whole key');
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable()->comment('Null: the default limit');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        foreach (['document_events', 'payment_events'] as $timeline) {
            Schema::table($timeline, function (Blueprint $table) {
                $table->foreignUlid('actor_api_key_id')->nullable()->after('actor_id')->comment('API key that acted; null for users and the system')->constrained('api_keys')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['document_events', 'payment_events'] as $timeline) {
            Schema::table($timeline, function (Blueprint $table) {
                $table->dropConstrainedForeignId('actor_api_key_id');
            });
        }

        Schema::dropIfExists('api_keys');
    }
};
