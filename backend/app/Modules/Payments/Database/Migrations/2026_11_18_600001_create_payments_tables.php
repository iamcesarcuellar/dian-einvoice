<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments received from customers and the invoices they were applied to. document_id has no
 * foreign key to the documents of Billing on purpose: this module talks to Billing only through its
 * ledger, and will move to a service of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->ulid('number_template_id')->comment('The receipt numbering it took its number from');
            $table->string('prefix', 10)->nullable();
            $table->unsignedBigInteger('number');
            $table->string('full_number', 40);
            $table->date('payment_date');
            $table->foreignUlid('contact_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 450)->comment('The customer as it was when the payment was recorded');
            $table->string('customer_identification', 40)->nullable();
            $table->decimal('amount', 18, 2)->comment('The sum applied to its invoices');
            $table->char('currency', 3)->default('COP');
            $table->string('payment_means_code', 10)->comment('Table 13.3.4.2 of annex 1.9, as /reference lists it');
            $table->string('reference', 100)->nullable()->comment('Bank or check reference');
            $table->string('notes', 2000)->nullable();
            $table->string('status', 20)->comment('recorded or voided');
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUlid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number_template_id', 'number']);
            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->ulid('document_id')->comment('The invoice, in Billing');
            $table->string('document_number', 40)->comment('Its number, as the ledger returned it');
            $table->date('document_issue_date')->nullable();
            $table->date('document_due_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2)->comment('What was left to collect on the invoice right after the payment');

            $table->unique(['payment_id', 'document_id']);
            $table->index(['company_id', 'document_id']);
        });

        // Timeline of the payment.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['payment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
