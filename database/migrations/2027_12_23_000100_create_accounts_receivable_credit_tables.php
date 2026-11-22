<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts receivable and credit management (Mod 013): open items per due date, the allocations that settle them,
 * the credit limits with their validity and reaction policy, the audited overrides of a credit block, the prudential
 * provision rates per aging bucket and the log of the customer statements sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receivable_items')) {
            Schema::create('receivable_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('invoice_id')->nullable()->index();
                $table->unsignedSmallInteger('sequence')->default(1); // installment of the invoice
                $table->string('document_type', 30)->default('invoice'); // invoice, debit_note
                $table->string('document_number', 100)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('original_amount', 15, 2);
                $table->decimal('open_amount', 15, 2);
                $table->date('issue_date');
                $table->date('due_date');
                $table->date('value_date')->nullable(); // value date of the latest settlement
                $table->string('status', 20)->default('open'); // open, partially_paid, paid, disputed, written_off (overdue is derived)
                $table->string('dispute_reason', 500)->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'invoice_id', 'sequence'], 'receivable_items_source_unique');
                $table->index(['tenant_id', 'customer_id', 'status']);
                $table->index(['tenant_id', 'status', 'due_date']);
            });
        }

        if (! Schema::hasTable('receivable_allocations')) {
            Schema::create('receivable_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('receivable_item_id')->constrained('receivable_items')->cascadeOnDelete();
                $table->string('source_type', 30); // payment, credit_note, debit_adjustment, write_off, discount
                $table->string('origin', 10)->default('manual'); // manual, sync (mirrors the paid total of the invoice)
                $table->uuid('source_id')->nullable()->index();
                $table->string('source_reference', 100)->nullable();
                $table->decimal('amount', 15, 2); // negative for a debit adjustment, which raises the open amount
                $table->date('value_date');
                $table->text('notes')->nullable();
                $table->uuid('allocated_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'receivable_item_id']);
                $table->index(['tenant_id', 'source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('customer_credit_limits')) {
            Schema::create('customer_credit_limits', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('currency', 3)->default('EUR');
                $table->decimal('granted_limit', 15, 2)->default(0);
                $table->decimal('insured_limit', 15, 2)->default(0);
                $table->string('insurer_name', 150)->nullable();
                $table->string('policy_number', 100)->nullable();
                $table->decimal('coverage_percentage', 5, 2)->default(0);
                $table->unsignedInteger('max_overdue_days')->nullable();
                $table->decimal('overdue_tolerance_amount', 15, 2)->default(0);
                $table->string('reaction_policy', 30)->default('warning'); // warning, block_order, block_delivery_note, credit_hold
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'valid_from']);
            });
        }

        if (! Schema::hasTable('customer_credit_overrides')) {
            Schema::create('customer_credit_overrides', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('scope', 20)->default('all'); // all, order, delivery_note
                $table->text('reason');
                $table->uuid('authorized_by');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->uuid('revoked_by')->nullable();
                $table->text('revoke_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('receivable_provision_rates')) {
            Schema::create('receivable_provision_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('bucket_key', 20);
                $table->decimal('rate_percent', 5, 2);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'bucket_key']);
            });
        }

        if (! Schema::hasTable('customer_statement_dispatches')) {
            Schema::create('customer_statement_dispatches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->date('period_from');
                $table->date('period_to');
                $table->string('recipient', 255);
                $table->string('locale', 5)->default('en');
                $table->string('status', 20)->default('queued'); // queued, sent, failed
                $table->text('error')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_statement_dispatches');
        Schema::dropIfExists('receivable_provision_rates');
        Schema::dropIfExists('customer_credit_overrides');
        Schema::dropIfExists('customer_credit_limits');
        Schema::dropIfExists('receivable_allocations');
        Schema::dropIfExists('receivable_items');
    }
};
