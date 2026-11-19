<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to join a company with a role. Only the SHA-256 of the token is kept: the token
 * itself travels only in the link of the invitation email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('email')->comment('Lowercased');
            $table->string('role', 16)->comment('owner, admin, member or viewer');
            $table->char('token_hash', 64)->unique()->comment('SHA-256 hex of the token in the email link');
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->comment('Seven days after it was created or last resent');
            $table->timestamp('last_sent_at')->nullable()->comment('When the mail server last took the email; null if it never did');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
