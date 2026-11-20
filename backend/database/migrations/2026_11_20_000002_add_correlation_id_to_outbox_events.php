<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journey an outbox event belongs to (App\Support\Observability\Correlation): recorded with the event, carried
 * by its SNS and SQS messages and by the jobs it starts, so every log line of the journey names it. Null for the
 * events recorded before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->char('correlation_id', 26)->nullable()->after('type')->comment('The journey of the event: a ULID in lower case');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn('correlation_id');
        });
    }
};
