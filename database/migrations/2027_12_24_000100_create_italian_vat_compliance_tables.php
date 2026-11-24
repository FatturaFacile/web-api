<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Italian VAT compliance (Mod 029): sectional VAT registers with progressive protocol numbers and definitive print, the
 * daily receipts and third-party goods registers, pro-rata rates, LIPE communications, the annual VAT reconciliation and the
 * Certificazione Unica with its F24 payments. Dialect-agnostic: runs unchanged on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_vat_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('register_type', 20); // sales, purchases, receipts, third_party_goods
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('document_kind', 20)->nullable(); // ordinary, deferred, credit_note, debit_note, self_invoice, reverse_charge
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'register_type']);
        });

        Schema::create('it_vat_register_prints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('it_vat_sections')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('first_protocol');
            $table->unsignedInteger('last_protocol');
            $table->unsignedInteger('entries_count');
            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');
            $table->decimal('total_taxable', 15, 2)->default(0);
            $table->decimal('total_vat', 15, 2)->default(0);
            $table->decimal('total_deductible_vat', 15, 2)->default(0);
            $table->string('content_hash', 64);
            $table->string('stamp_reference', 40);
            $table->timestamp('printed_at');
            $table->uuid('printed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'section_id', 'year', 'last_protocol'], 'it_vat_prints_unique_range');
            $table->unique(['tenant_id', 'stamp_reference']);
        });

        Schema::create('it_vat_register_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('it_vat_sections')->cascadeOnDelete();
            $table->string('register_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('protocol_number');
            $table->date('entry_date');
            $table->date('document_date')->nullable();
            $table->string('document_number', 50)->nullable();
            $table->string('document_kind', 20)->default('ordinary');
            $table->string('document_type_code', 4)->nullable(); // TD01..TD28
            $table->uuid('contact_id')->nullable()->index();
            $table->string('counterparty_name', 200)->nullable();
            $table->string('counterparty_vat', 30)->nullable();
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->string('vat_nature', 6)->nullable(); // N1 .. N7 when no VAT is charged
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('deduction_percentage', 5, 2)->default(100);
            $table->decimal('deductible_vat', 15, 2)->default(0);
            $table->decimal('non_deductible_vat', 15, 2)->default(0);
            $table->string('non_deductible_reason', 20)->nullable(); // pro_rata, art_19_bis_1, other
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('print_id')->nullable()->constrained('it_vat_register_prints')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section_id', 'year', 'protocol_number'], 'it_vat_entries_protocol_unique');
            $table->index(['tenant_id', 'register_type', 'entry_date'], 'it_vat_entries_period_index');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('it_vat_pro_rata_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('deductible_operations', 15, 2)->default(0);
            $table->decimal('total_operations', 15, 2)->default(0);
            $table->unsignedTinyInteger('provisional_percentage')->default(100);
            $table->unsignedTinyInteger('definitive_percentage')->nullable();
            $table->boolean('is_definitive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year']);
        });

        Schema::create('it_vat_ventilations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_takings', 15, 2);
            $table->json('purchase_basis');
            $table->json('breakdown');
            $table->decimal('total_taxable', 15, 2);
            $table->decimal('total_vat', 15, 2);
            $table->timestamp('registered_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entry_date']);
        });

        Schema::create('it_third_party_goods_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('it_vat_sections')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('protocol_number');
            $table->foreignUuid('print_id')->nullable()->constrained('it_vat_register_prints')->nullOnDelete();
            $table->date('movement_date');
            $table->string('movement_type', 20); // received, returned, processed, sold
            $table->uuid('contact_id')->index();
            $table->string('description', 200);
            $table->string('unit', 10)->default('pz');
            $table->decimal('quantity', 15, 3);
            $table->decimal('value', 15, 2)->default(0);
            $table->string('document_number', 50)->nullable();
            $table->date('document_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section_id', 'year', 'protocol_number'], 'it_goods_protocol_unique');
            $table->index(['tenant_id', 'contact_id', 'description']);
        });

        Schema::create('it_lipe_communications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->string('status', 20)->default('draft'); // draft, validated, signed, ready, transmitted, rejected
            $table->json('settlement_ids');
            $table->json('totals');
            $table->string('file_name', 80)->nullable();
            $table->longText('xml_content')->nullable();
            $table->json('xsd_errors')->nullable();
            $table->string('reconciliation_status', 20)->default('not_run'); // not_run, balanced, unbalanced
            $table->json('reconciliation')->nullable();
            $table->string('signature_status', 20)->default('unsigned'); // unsigned, signed, invalid
            $table->string('signature_digest', 64)->nullable();
            $table->string('signer_fingerprint', 80)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('transmission_protocol', 40)->nullable();
            $table->timestamp('transmitted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year', 'quarter']);
        });

        Schema::create('it_annual_vat_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status', 20)->default('draft'); // draft, finalized
            $table->json('periods');
            $table->decimal('total_output_vat', 15, 2)->default(0);
            $table->decimal('total_input_vat', 15, 2)->default(0);
            $table->unsignedTinyInteger('pro_rata_percentage')->default(100);
            $table->decimal('pro_rata_adjustment', 15, 2)->default(0);
            $table->decimal('prior_year_credit', 15, 2)->default(0);
            $table->decimal('interest_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('annual_debit', 15, 2)->default(0);
            $table->decimal('annual_credit', 15, 2)->default(0);
            $table->decimal('ledger_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->boolean('is_reconciled')->default(false);
            $table->json('diagnostics')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year']);
        });

        Schema::create('it_cu_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->uuid('supplier_id');
            $table->string('recipient_name', 200);
            $table->string('recipient_tax_code', 16)->nullable();
            $table->string('recipient_vat_number', 16)->nullable();
            $table->string('recipient_kind', 10); // natural, legal
            $table->string('reason_code', 2); // causale (A, M, R, ...)
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('non_taxable_amount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('withheld_amount', 15, 2)->default(0);
            $table->decimal('contributions_amount', 15, 2)->default(0);
            $table->unsignedInteger('payments_count')->default(0);
            $table->string('status', 20)->default('draft'); // draft, validated, issued
            $table->json('validation_errors')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year', 'supplier_id', 'reason_code'], 'it_cu_unique');
        });

        Schema::create('it_f24_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('tax_code', 6); // codice tributo (1040, 1038, 1713, ...)
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->string('f24_reference', 40)->nullable();
            $table->uuid('withholding_settlement_id')->nullable()->index();
            $table->string('status', 20)->default('due'); // due, paid, partial, overpaid
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year', 'month', 'tax_code']);
        });

        Schema::table('declarations_of_intent', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('declarations_of_intent', function (Blueprint $table): void {
            $table->dropColumn(['revoked_at', 'revocation_reason']);
        });

        foreach ([
            'it_f24_payments', 'it_cu_certifications', 'it_annual_vat_reconciliations', 'it_lipe_communications',
            'it_third_party_goods_movements', 'it_vat_ventilations', 'it_vat_pro_rata_rates', 'it_vat_register_entries',
            'it_vat_register_prints', 'it_vat_sections',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
