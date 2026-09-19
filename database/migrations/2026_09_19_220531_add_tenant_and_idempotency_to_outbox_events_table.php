<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->string('idempotency_key')->nullable()->after('attempts');
        });

        // Backfill tenant_id from JSON payload when present (Postgres / SQLite JSON).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE outbox_events
                SET tenant_id = (payload->>'tenant_id')::uuid
                WHERE payload->>'tenant_id' IS NOT NULL
                  AND tenant_id IS NULL
            ");
        }

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropIndex(['tenant_id', 'status', 'created_at']);
            $table->dropIndex(['tenant_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'idempotency_key']);
        });
    }
};
