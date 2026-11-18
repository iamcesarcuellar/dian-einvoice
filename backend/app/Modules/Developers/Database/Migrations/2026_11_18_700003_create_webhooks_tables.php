<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks: subscriptions of each company, the events published to them (the body sent, frozen
 * once), one delivery per event and subscription, and every attempt of each delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('description', 255)->nullable();
            $table->json('events')->comment('Public event types it receives');
            $table->boolean('is_active')->default(true);
            $table->text('secret')->comment('Encrypted signing secret, whsec_...');
            $table->timestamp('secret_rotated_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable()->comment('Switched off automatically after failures in a row');
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_delivery_status', 20)->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->char('source_event_id', 26)->nullable()->unique()->comment('The outbox event it comes from; null for test events');
            $table->longText('payload')->comment('The JSON body sent, byte for byte');
            $table->timestamp('created_at');

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained('webhook_events')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('status', 20)->comment('pending, succeeded or failed');
            $table->unsignedSmallInteger('run')->default(1)->comment('1, plus one per manual redelivery');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Attempts made, every run included');
            $table->unsignedSmallInteger('run_attempts')->default(0)->comment('Attempts of the current run');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'event_id']);
            $table->index(['subscription_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('webhook_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedSmallInteger('run');
            $table->unsignedSmallInteger('attempt');
            $table->string('url', 2048);
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('outcome', 30)->nullable()->comment('succeeded, http_error, timeout, connection_error, blocked or abandoned');
            $table->string('error', 500)->nullable();
            $table->text('response_snippet')->nullable()->comment('The first 1000 characters of the answer');

            $table->index(['delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_attempts');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
