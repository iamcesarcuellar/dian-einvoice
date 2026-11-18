<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices now, and credit and debit notes from stage 8, share these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->comment('invoice; credit_note and debit_note from stage 8');
            $table->string('status', 20)->comment('draft or issued');
            $table->foreignUlid('number_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('prefix', 4)->nullable()->comment('Set on issue; an empty string when the numbering has no prefix');
            $table->unsignedBigInteger('number')->nullable()->comment('Consecutive number, set on issue');
            $table->string('full_number', 24)->nullable()->comment('Prefix and number');
            $table->foreignUlid('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('issue_date')->nullable()->comment('Colombian calendar date of issue');
            $table->string('issue_time', 14)->nullable()->comment('Colombian time of issue with its offset, such as 09:20:00-05:00');
            $table->timestamp('issued_at')->nullable()->comment('Instant of issue, in UTC');
            $table->date('due_date')->nullable();
            $table->char('payment_form', 1)->nullable()->comment('DIAN payment form: 1 cash, 2 credit');
            $table->string('payment_means_code', 3)->nullable()->comment('DIAN payment means code');
            $table->char('currency', 3)->default('COP');
            $table->text('notes')->nullable();
            $table->decimal('subtotal_amount', 18, 2)->default(0)->comment('Quantity times price, before discounts');
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('line_extension_amount', 18, 2)->default(0);
            $table->decimal('tax_exclusive_amount', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('tax_inclusive_amount', 18, 2)->default(0);
            $table->decimal('payable_amount', 18, 2)->default(0);
            $table->decimal('withholding_total', 18, 2)->default(0);
            $table->decimal('amount_due', 18, 2)->default(0)->comment('Payable amount minus withholdings');
            $table->json('company_snapshot')->nullable()->comment('Company tax data, frozen on issue');
            $table->json('customer_snapshot')->nullable()->comment('Customer data, frozen on issue');
            $table->json('numbering_snapshot')->nullable()->comment('Resolution, prefix, range and validity, frozen on issue');
            // DIAN values, set on issue (annex 1.9).
            $table->char('cufe', 96)->nullable()->unique()->comment('SHA-384 hex of the CUFE fields');
            $table->char('software_security_code', 96)->nullable()->comment('SHA-384 hex of software ID, PIN and number');
            $table->string('qr_url')->nullable()->comment('DIAN verification URL, drawn as the QR code');
            $table->unsignedTinyInteger('dian_environment')->nullable()->comment('2: habilitación (test)');
            $table->json('dian_snapshot')->nullable()->comment('Software data the XML uses, frozen on issue');
            // Unsigned UBL 2.1 XML, generated after the issue is committed.
            $table->string('xml_status', 20)->nullable()->comment('pending, generated or failed; null for drafts');
            $table->string('xml_path')->nullable()->comment('Path in the private document storage');
            $table->char('xml_sha256', 64)->nullable();
            $table->unsignedInteger('xml_size')->nullable()->comment('Bytes');
            $table->timestamp('xml_generated_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Drafts have no prefix or number, so they never collide.
            $table->unique(['company_id', 'type', 'prefix', 'number'], 'documents_number_unique');
            $table->index(['company_id', 'type', 'status']);
        });

        Schema::create('document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('item_id')->nullable()->constrained()->restrictOnDelete();
            // The line keeps its own copy of the item and tax data: later changes to them do not alter it.
            $table->string('code', 50)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('standard_scheme', 20)->nullable()->comment('Product standard of the item, such as UNSPSC');
            $table->string('standard_code', 50)->nullable();
            $table->string('unit_code', 3)->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_price', 18, 6)->default(0);
            $table->decimal('discount_rate', 7, 4)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('line_extension_amount', 18, 2)->default(0);
            $table->foreignUlid('tax_id')->nullable()->comment('Null means excluded: no tax')->constrained()->restrictOnDelete();
            $table->string('tax_code', 2)->nullable();
            $table->string('tax_name', 100)->nullable();
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['document_id', 'position']);
        });

        Schema::create('document_taxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('dian_code', 2);
            $table->string('name', 100);
            $table->decimal('rate', 7, 4);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
        });

        Schema::create('document_withholdings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('withholding_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('dian_code', 2);
            $table->string('name', 100);
            $table->decimal('rate', 7, 4);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('amount', 18, 2);
        });

        // Timeline of the document.
        Schema::create('document_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignUlid('actor_id')->nullable()->comment('User who acted; null for the system')->constrained('users')->nullOnDelete();

            $table->index(['document_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_withholdings');
        Schema::dropIfExists('document_taxes');
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('documents');
    }
};
