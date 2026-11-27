<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 203 — Down-payment chain: the chain of advance invoices (TD02), pro-forma requests, earnest money and security deposits
 * between a source document (order, quote, contract) and its final settlement invoice, with the per-VAT-rate analytical ledger and
 * the deduction lines of the final invoice (Issue #449). Dialect-agnostic: no JSON operators, no partial indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->chains();
        $this->installments();
        $this->links();
        $this->linkVatLines();
        $this->deposits();
        $this->deductionLines();
    }

    public function down(): void
    {
        foreach (['final_settlement_deduction_lines', 'earnest_and_security_deposits', 'down_payment_link_vat_lines', 'down_payment_links', 'down_payment_schedule_installments', 'down_payment_chains'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function money(Blueprint $table, string ...$columns): void
    {
        foreach ($columns as $column) {
            $table->decimal($column, 18, 2)->default(0);
        }
    }

    private function chains(): void
    {
        if (Schema::hasTable('down_payment_chains')) {
            return;
        }

        Schema::create('down_payment_chains', function (Blueprint $table): void {
            $this->base($table);
            $table->string('chain_number', 40);
            $table->string('source_type', 20); // sales_order, quote, contract
            $table->uuid('source_id')->index();
            $table->string('source_number', 60)->nullable();
            $table->uuid('sales_order_id')->nullable()->index(); // the order the invoices bill (the source, or the order of a converted quote)
            $table->uuid('customer_id')->index();
            $table->string('customer_name', 200)->nullable();
            $table->string('currency', 3);
            $table->string('jurisdiction', 2)->default('IT'); // IT, CH
            $this->money($table, 'total_contract_net', 'total_contract_vat', 'total_contract_gross', 'total_earnest_deposits_held', 'total_security_deposits_held', 'total_advance_invoiced_net', 'total_advance_invoiced_vat', 'total_advance_invoiced_gross', 'total_credited_advances_gross', 'residual_order_gross');
            $table->string('status', 30)->default('draft')->index();
            $table->json('vat_bases')->nullable(); // net of the source per VAT rate when the chain was opened: {"22.00": "1000.00"}
            $table->json('supply_lines')->nullable(); // the supply of a contract source: list of {description, vat_rate, net}
            $table->uuid('final_invoice_id')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'chain_number']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    private function installments(): void
    {
        if (Schema::hasTable('down_payment_schedule_installments')) {
            return;
        }

        Schema::create('down_payment_schedule_installments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('chain_id')->index()->constrained('down_payment_chains')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('milestone_label', 200);
            $table->string('trigger_type', 30);
            $table->date('due_date')->nullable();
            $table->string('calculation_mode', 20);
            $table->decimal('percentage', 7, 4)->nullable();
            $this->money($table, 'amount_net', 'amount_vat', 'amount_gross');
            $table->string('instrument_type', 30);
            $table->string('status', 20)->default('planned')->index();
            $table->json('vat_breakdown')->nullable(); // per-VAT-rate split computed by the allocator
            $table->uuid('linked_document_id')->nullable(); // proforma request or deposit receipt
            $table->uuid('linked_invoice_id')->nullable(); // the TD02
            $this->audit($table);
            $table->unique(['chain_id', 'sequence']);
        });
    }

    private function links(): void
    {
        if (Schema::hasTable('down_payment_links')) {
            return;
        }

        Schema::create('down_payment_links', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('chain_id')->index()->constrained('down_payment_chains')->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->uuid('source_id');
            $table->uuid('installment_id')->nullable()->index();
            $table->string('link_type', 30)->index();
            $table->uuid('document_id')->nullable()->index();
            $table->string('document_number', 60)->nullable();
            $table->date('document_date')->nullable();
            $table->uuid('parent_link_id')->nullable()->index();
            $table->string('status', 20)->default('issued')->index();
            $this->money($table, 'net_amount', 'vat_amount', 'gross_amount', 'credited_gross_amount', 'settled_gross_amount');
            $this->audit($table);
        });
    }

    private function linkVatLines(): void
    {
        if (Schema::hasTable('down_payment_link_vat_lines')) {
            return;
        }

        Schema::create('down_payment_link_vat_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('down_payment_link_id')->index()->constrained('down_payment_links')->cascadeOnDelete();
            $table->uuid('vat_rate_id')->nullable();
            $table->string('vat_code', 20);
            $table->decimal('vat_percentage', 7, 3);
            $this->money($table, 'net_amount', 'vat_amount', 'gross_amount', 'credited_net_amount', 'credited_vat_amount', 'credited_gross_amount', 'settled_net_amount', 'settled_vat_amount', 'settled_gross_amount', 'residual_net_amount', 'residual_vat_amount', 'residual_gross_amount');
            $this->audit($table);
        });
    }

    private function deposits(): void
    {
        if (Schema::hasTable('earnest_and_security_deposits')) {
            return;
        }

        Schema::create('earnest_and_security_deposits', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('chain_id')->index()->constrained('down_payment_chains')->cascadeOnDelete();
            $table->uuid('installment_id')->nullable()->index();
            $table->string('deposit_type', 40);
            $table->string('receipt_number', 40);
            $table->date('issue_date');
            $table->date('payment_date')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('vat_exemption_code', 20)->nullable(); // N1 (Art. 15 DPR 633/72) for IT, OUT_OF_SCOPE for CH
            $table->boolean('marca_da_bollo_applied')->default(false);
            $table->decimal('marca_da_bollo_amount', 8, 2)->default(0);
            $table->string('payment_account', 10)->default('bank'); // bank, cash
            $table->string('status', 40)->default('issued_unpaid')->index();
            $table->uuid('converted_invoice_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('resolution_journal_entry_id')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->date('resolved_at')->nullable();
            $table->decimal('penalty_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'receipt_number']);
        });
    }

    private function deductionLines(): void
    {
        if (Schema::hasTable('final_settlement_deduction_lines')) {
            return;
        }

        Schema::create('final_settlement_deduction_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('chain_id')->index()->constrained('down_payment_chains')->cascadeOnDelete();
            $table->uuid('final_invoice_id')->index();
            $table->uuid('final_invoice_item_id')->nullable();
            $table->uuid('referenced_link_id')->nullable()->index();
            $table->string('referenced_invoice_number', 60)->nullable();
            $table->date('referenced_invoice_date')->nullable();
            $table->uuid('vat_rate_id')->nullable();
            $table->decimal('vat_percentage', 7, 3);
            $this->money($table, 'deducted_net', 'deducted_vat', 'deducted_gross');
            $this->audit($table);
        });
    }
};
