<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #268: smart reconciliation proposals, Modello F24 delegations, CSV statement profiles and open banking sync
 * runs. Portable between SQLite and PostgreSQL (no engine specific SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->string('cbi_cuc', 8)->nullable();
        });

        Schema::create('reconciliation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('bank_statement_line_id')->index();
            $table->string('document_type', 20);
            $table->uuid('document_id');
            $table->string('rule', 40);
            $table->unsignedSmallInteger('confidence');
            $table->decimal('matched_amount', 18, 2);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('fx_difference', 18, 2)->default(0);
            $table->decimal('residual_amount', 18, 2)->default(0);
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending')->index();
            $table->json('evidence')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->uuid('applied_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_statement_line_id', 'document_type', 'document_id'], 'recon_proposals_line_document_unique');
        });

        Schema::create('f24_tax_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('code', 12);
            $table->string('section', 20)->index();
            $table->string('translation_key', 120);
            $table->boolean('requires_installment')->default(false);
            $table->string('period_format', 20)->default('year');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section', 'code']);
        });

        Schema::create('f24_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('bank_account_id')->nullable()->index();
            $table->string('taxpayer_fiscal_code', 16);
            $table->string('taxpayer_name', 60);
            $table->string('taxpayer_first_name', 60)->nullable();
            $table->date('taxpayer_birth_date')->nullable();
            $table->string('taxpayer_birth_place', 40)->nullable();
            $table->string('taxpayer_birth_province', 2)->nullable();
            $table->string('taxpayer_gender', 1)->nullable();
            $table->date('payment_date');
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->decimal('total_payable', 18, 2)->default(0);
            $table->string('file_name', 120)->nullable();
            $table->longText('file_content')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('f24_delegation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('f24_delegation_id')->index();
            $table->string('section', 20);
            $table->string('tax_code', 12);
            $table->string('institution_code', 20)->nullable();
            $table->string('reference_number', 20)->nullable();
            $table->string('installment', 8)->nullable();
            $table->string('reference_period', 8)->nullable();
            $table->string('reference_year', 4)->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('f24_delegation_id')->references('id')->on('f24_delegations')->cascadeOnDelete();
        });

        Schema::create('statement_csv_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('delimiter', 1)->default(';');
            $table->boolean('has_header')->default(true);
            $table->string('date_format', 30)->default('d/m/Y');
            $table->string('decimal_separator', 1)->default(',');
            $table->string('thousands_separator', 1)->nullable();
            $table->string('account_iban', 34)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->json('columns');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('open_banking_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('consent_id')->index();
            $table->string('driver', 60);
            $table->string('status', 20)->index();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->uuid('bank_statement_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_banking_sync_runs');
        Schema::dropIfExists('statement_csv_profiles');
        Schema::dropIfExists('f24_delegation_lines');
        Schema::dropIfExists('f24_delegations');
        Schema::dropIfExists('f24_tax_codes');
        Schema::dropIfExists('reconciliation_proposals');

        Schema::table('payment_batches', function (Blueprint $table): void {
            $table->dropColumn('cbi_cuc');
        });
    }
};
