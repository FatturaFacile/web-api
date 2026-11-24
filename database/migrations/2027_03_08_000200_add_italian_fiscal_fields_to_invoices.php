<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: every column is nullable or has a default, so existing documents keep reading as before (no stated
     * type is TD01, no fund or Enasarco). The issued-invoice trigger compares whole rows and ignores schema changes.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'document_type')) {
                $table->string('document_type', 4)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'vat_nature')) {
                $table->string('vat_nature', 4)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'source_passive_invoice_id')) {
                $table->uuid('source_passive_invoice_id')->nullable()->index();
            }
            if (! Schema::hasColumn('invoices', 'linked_document_number')) {
                $table->string('linked_document_number', 20)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'linked_document_date')) {
                $table->date('linked_document_date')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'declaration_of_intent_id')) {
                $table->uuid('declaration_of_intent_id')->nullable()->index();
            }
            if (! Schema::hasColumn('invoices', 'skip_declaration_of_intent')) {
                $table->boolean('skip_declaration_of_intent')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_type')) {
                $table->string('withholding_tax_type', 4)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_reason')) {
                $table->string('withholding_tax_reason', 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'withholding_tax_base_percent')) {
                $table->decimal('withholding_tax_base_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'stamp_duty_charged')) {
                $table->boolean('stamp_duty_charged')->default(true);
            }
            if (! Schema::hasColumn('invoices', 'fund_vat_rate')) {
                $table->decimal('fund_vat_rate', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'fund_vat_amount')) {
                $table->decimal('fund_vat_amount', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'fund_subject_to_withholding')) {
                $table->boolean('fund_subject_to_withholding')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'enasarco_enabled')) {
                $table->boolean('enasarco_enabled')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'enasarco_agent_type')) {
                $table->string('enasarco_agent_type', 20)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_base')) {
                $table->decimal('enasarco_base', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_percent')) {
                $table->decimal('enasarco_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'enasarco_amount')) {
                $table->decimal('enasarco_amount', 15, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'document_type', 'vat_nature', 'source_passive_invoice_id', 'linked_document_number', 'linked_document_date',
                'declaration_of_intent_id', 'skip_declaration_of_intent', 'withholding_tax_type', 'withholding_tax_reason',
                'withholding_tax_base_percent', 'stamp_duty_charged', 'fund_vat_rate', 'fund_vat_amount',
                'fund_subject_to_withholding', 'enasarco_enabled', 'enasarco_agent_type', 'enasarco_base', 'enasarco_percent',
                'enasarco_amount',
            ], static fn (string $column): bool => Schema::hasColumn('invoices', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
