<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox: events written in the same transaction as the change they describe,
 * published later by a relay (not built yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('aggregate_type', 50);
            $table->char('aggregate_id', 26);
            $table->string('type', 100)->comment('Such as invoice.issued');
            $table->json('payload');
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();

            $table->index(['published_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
