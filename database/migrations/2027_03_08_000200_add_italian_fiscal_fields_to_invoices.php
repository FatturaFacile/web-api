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
            $table->string('document_type', 4)->nullable();
            $table->string('vat_nature', 4)->nullable();
            $table->uuid('source_passive_invoice_id')->nullable()->index();
            $table->string('linked_document_number', 20)->nullable();
            $table->date('linked_document_date')->nullable();
            $table->uuid('declaration_of_intent_id')->nullable()->index();
            $table->boolean('skip_declaration_of_intent')->default(false);
            $table->string('withholding_tax_type', 4)->nullable();
            $table->string('withholding_tax_reason', 2)->nullable();
            $table->decimal('withholding_tax_base_percent', 5, 2)->nullable();
            $table->boolean('stamp_duty_charged')->default(true);
            $table->decimal('fund_vat_rate', 5, 2)->nullable();
            $table->decimal('fund_vat_amount', 15, 2)->default(0);
            $table->boolean('fund_subject_to_withholding')->default(false);
            $table->boolean('enasarco_enabled')->default(false);
            $table->string('enasarco_agent_type', 20)->nullable();
            $table->decimal('enasarco_base', 15, 2)->nullable();
            $table->decimal('enasarco_percent', 5, 2)->nullable();
            $table->decimal('enasarco_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'document_type', 'vat_nature', 'source_passive_invoice_id', 'linked_document_number', 'linked_document_date',
                'declaration_of_intent_id', 'skip_declaration_of_intent', 'withholding_tax_type', 'withholding_tax_reason',
                'withholding_tax_base_percent', 'stamp_duty_charged', 'fund_vat_rate', 'fund_vat_amount',
                'fund_subject_to_withholding', 'enasarco_enabled', 'enasarco_agent_type', 'enasarco_base', 'enasarco_percent',
                'enasarco_amount',
            ]);
        });
    }
};
