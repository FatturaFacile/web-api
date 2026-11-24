<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts payable (Mod 015): the supplier open items (partitario fornitori) with their dispute and administrative holds
 * and audit history, the supplier cash-discount (Skonto) agreements, and the payment runs (distinte di pagamento) with
 * their transfers grouped by creditor IBAN and the credit-note offsets. Dialect-agnostic: runs unchanged on SQLite and
 * PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_open_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id')->index();
            $table->uuid('passive_invoice_id')->nullable()->index();
            $table->string('source', 20); // electronic_xml, swiss_qr, manual
            $table->string('kind', 12)->default('invoice'); // invoice, credit_note
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->date('due_date');
            $table->string('currency', 3);
            $table->decimal('original_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('discount_taken_amount', 15, 2)->default(0);
            $table->decimal('open_amount', 15, 2);
            $table->text('creditor_iban')->nullable();
            $table->string('status', 20)->default('open'); // open, in_proposal, authorized, paid, disputed, blocked
            $table->string('status_before_hold', 20)->nullable();
            $table->text('hold_reason')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->uuid('held_by')->nullable();
            $table->decimal('skonto_percentage', 5, 2)->nullable(); // document-level override of the supplier terms
            $table->unsignedSmallInteger('skonto_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status', 'due_date']);
            $table->index(['tenant_id', 'supplier_id', 'currency']);
            $table->unique(['tenant_id', 'passive_invoice_id'], 'ap_open_items_invoice_unique');
        });

        Schema::create('ap_open_item_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('open_item_id')->constrained('ap_open_items')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['open_item_id', 'occurred_at']);
        });

        Schema::create('ap_supplier_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id')->index();
            $table->unsignedSmallInteger('net_days')->nullable();
            $table->decimal('skonto_percentage', 5, 2);
            $table->unsignedSmallInteger('skonto_days');
            $table->string('clause', 255)->nullable();
            $table->string('reference', 100)->nullable(); // contract or agreement the terms come from
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'supplier_id', 'is_active']);
        });

        Schema::create('ap_payment_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('run_number', 40);
            $table->string('status', 24)->default('draft'); // draft, pending_cfo_approval, authorized, rejected, executed, cancelled
            $table->string('currency', 3);
            $table->date('cutoff_date');
            $table->date('execution_date');
            $table->uuid('bank_account_id')->nullable();
            $table->boolean('include_skonto')->default(true);
            $table->boolean('net_credit_notes')->default(true);
            $table->json('supplier_ids')->nullable();
            $table->decimal('debit_total', 15, 2)->default(0);
            $table->decimal('credit_netted_total', 15, 2)->default(0);
            $table->decimal('skonto_total', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('transfers_count')->default(0);
            $table->json('excluded')->nullable(); // items left out of the run with the reason
            $table->text('notes')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'run_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ap_payment_run_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payment_run_id')->constrained('ap_payment_runs')->cascadeOnDelete();
            $table->uuid('supplier_id')->index();
            $table->text('creditor_iban')->nullable();
            $table->string('currency', 3);
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('skonto_amount', 15, 2)->default(0);
            $table->decimal('credit_netted_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0); // what the bank transfers: gross less skonto and credit notes
            $table->unsignedInteger('items_count')->default(0);
            $table->string('reference', 140)->nullable();
            $table->string('status', 12)->default('pending'); // pending, paid, netted (nothing to transfer)
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::create('ap_payment_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payment_run_id')->constrained('ap_payment_runs')->cascadeOnDelete();
            $table->foreignUuid('open_item_id')->constrained('ap_open_items')->cascadeOnDelete();
            $table->uuid('transfer_id')->nullable()->index();
            $table->uuid('supplier_id')->index();
            $table->string('kind', 12); // invoice, credit_note
            $table->string('selection_reason', 20); // due, skonto, credit_note
            $table->decimal('open_amount', 15, 2); // the balance of the item when the run was prepared
            $table->decimal('skonto_percentage', 5, 2)->nullable();
            $table->date('skonto_deadline')->nullable();
            $table->decimal('skonto_amount', 15, 2)->default(0);
            $table->decimal('credit_applied_amount', 15, 2)->default(0); // credit notes offset against the invoice
            $table->decimal('cash_amount', 15, 2)->default(0); // transferred to the supplier for this invoice
            $table->decimal('credit_used_amount', 15, 2)->default(0); // for a credit note: the part offset in this run
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['payment_run_id', 'open_item_id']);
        });

        Schema::create('ap_payment_run_offsets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payment_run_id')->constrained('ap_payment_runs')->cascadeOnDelete();
            $table->uuid('debit_item_id')->index();
            $table->uuid('credit_item_id')->index();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->uuid('offset_passive_invoice_id')->nullable()->index(); // the credit note a credit_offset payment settles against
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropIndex(['offset_passive_invoice_id']);
            $table->dropColumn('offset_passive_invoice_id');
        });

        foreach (['ap_payment_run_offsets', 'ap_payment_run_items', 'ap_payment_run_transfers', 'ap_payment_runs', 'ap_supplier_terms', 'ap_open_item_events', 'ap_open_items'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
