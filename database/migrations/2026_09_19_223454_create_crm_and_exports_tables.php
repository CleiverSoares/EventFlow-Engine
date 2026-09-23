<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document', 14);
            $table->string('name');
            $table->timestamps();

            $table->index(['tenant_id', 'document']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'account_id']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
            $table->string('code');
            $table->string('status');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'opened_at']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('crm_deal_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->string('sku');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'deal_id']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->string('type');
            $table->string('subject');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'deal_id']);
        });

        Schema::create('crm_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->string('number');
            $table->date('due_date');
            $table->decimal('total', 14, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'deal_id']);
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('crm_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('crm_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('status');
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('report');
            $table->string('format')->default('csv');
            $table->string('status');
            $table->json('filters')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
        Schema::dropIfExists('crm_payments');
        Schema::dropIfExists('crm_invoice_lines');
        Schema::dropIfExists('crm_invoices');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deal_items');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_accounts');
    }
};
