<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 167 — Statutory financial statement dossier: Italian civil-code financial statements with the XBRL deposit
 * (Art. 2424-2425, 2435-bis, 2435-ter c.c., OIC 10) and the Swiss annual accounts (CO Art. 957-963b, Swiss GAAP FER),
 * with Nota Integrativa / Anhang workspace, crisis indicators (Art. 2086 c.c., DSCR) and PDF/A deposit artifacts.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Reference tables (line catalogue, account mappings, text
 * templates, ratio definitions) hold rows without a tenant (global) next to optional tenant overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lineDefinitions();
        $this->accountMappings();
        $this->textTemplates();
        $this->ratioDefinitions();
        $this->dossiers();
        $this->dossierLines();
        $this->notesWorkspaces();
        $this->depositPackages();
        $this->artifacts();
    }

    public function down(): void
    {
        foreach (['sfs_artifacts', 'sfs_deposit_packages', 'sfs_notes_workspaces', 'sfs_dossier_lines', 'sfs_dossiers', 'sfs_ratio_definitions', 'sfs_text_templates', 'sfs_account_mappings', 'sfs_line_definitions'] as $table) {
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

    private function lineDefinitions(): void
    {
        if (Schema::hasTable('sfs_line_definitions')) {
            return;
        }

        Schema::create('sfs_line_definitions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 60);
            $table->string('family', 3);
            $table->string('statement', 20);
            $table->string('variant', 20)->default('any');
            $table->string('parent_code', 60)->nullable();
            $table->smallInteger('sign')->default(1);
            $table->string('side', 20);
            $table->integer('sort_order')->default(0);
            $table->json('schemes');
            $table->json('formula')->nullable();
            $table->string('cf_group', 30)->nullable();
            $table->string('xbrl_element', 120)->nullable();
            $table->boolean('is_rounding')->default(false);
            $table->json('labels');
            $this->audit($table);

            $table->unique(['tenant_id', 'code']);
            $table->index(['family', 'statement']);
        });
    }

    private function accountMappings(): void
    {
        if (Schema::hasTable('sfs_account_mappings')) {
            return;
        }

        Schema::create('sfs_account_mappings', function (Blueprint $table): void {
            $this->base($table);
            $table->string('family', 2);
            $table->string('code_prefix', 40);
            $table->string('line_code', 60);
            $table->string('alt_line_code', 60)->nullable();
            $table->boolean('is_bifront')->default(false);
            $table->integer('priority')->default(0);
            $table->string('notes', 255)->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'family', 'code_prefix']);
            $table->index(['family', 'code_prefix']);
        });
    }

    private function textTemplates(): void
    {
        if (Schema::hasTable('sfs_text_templates')) {
            return;
        }

        Schema::create('sfs_text_templates', function (Blueprint $table): void {
            $this->base($table);
            $table->string('template_key', 100);
            $table->string('kind', 30);
            $table->string('family', 3)->default('ALL');
            $table->integer('sort_order')->default(0);
            $table->json('content');
            $table->json('placeholders')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'template_key']);
            $table->index(['kind', 'family']);
        });
    }

    private function ratioDefinitions(): void
    {
        if (Schema::hasTable('sfs_ratio_definitions')) {
            return;
        }

        Schema::create('sfs_ratio_definitions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->string('family', 3)->default('ALL');
            $table->string('unit', 12);
            $table->string('direction', 3)->nullable();
            $table->decimal('warning_threshold', 20, 4)->nullable();
            $table->decimal('critical_threshold', 20, 4)->nullable();
            $table->boolean('is_crisis_indicator')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('labels');
            $table->json('descriptions');
            $table->json('alert_messages');
            $this->audit($table);

            $table->unique(['tenant_id', 'code']);
        });
    }

    private function dossiers(): void
    {
        if (Schema::hasTable('sfs_dossiers')) {
            return;
        }

        Schema::create('sfs_dossiers', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignUuid('comparative_prior_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->string('accounting_standard', 30);
            $table->string('status', 20)->default('draft');
            $table->string('rounding_to_unit_mode', 30)->default('ExactCents');
            $table->string('income_statement_method', 20)->default('total_cost');
            $table->string('cash_flow_method', 10)->default('indirect');
            $table->string('locale', 5)->default('it');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('share_capital', 20, 4)->nullable();
            $table->json('balance_sheet_lines')->nullable();
            $table->json('income_statement_lines')->nullable();
            $table->json('cash_flow_statement')->nullable();
            $table->json('profit_allocation_proposal')->nullable();
            $table->json('hidden_reserves_config')->nullable();
            $table->json('options')->nullable();
            $table->json('eligibility')->nullable();
            $table->json('reclassification_report')->nullable();
            $table->json('quadrature')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->string('filing_reference', 120)->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'fiscal_year_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    private function dossierLines(): void
    {
        if (Schema::hasTable('sfs_dossier_lines')) {
            return;
        }

        Schema::create('sfs_dossier_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('dossier_id')->constrained('sfs_dossiers')->cascadeOnDelete();
            $table->string('statement', 20);
            $table->string('line_code', 60);
            $table->string('parent_code', 60)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_total')->default(false);
            $table->boolean('is_rounding')->default(false);
            $table->decimal('exact_amount', 20, 4)->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('comparative_amount', 20, 4)->nullable();
            $table->json('sources')->nullable();
            $this->audit($table);

            $table->unique(['dossier_id', 'statement', 'line_code']);
        });
    }

    private function notesWorkspaces(): void
    {
        if (Schema::hasTable('sfs_notes_workspaces')) {
            return;
        }

        Schema::create('sfs_notes_workspaces', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('dossier_id')->constrained('sfs_dossiers')->cascadeOnDelete();
            $table->string('taxonomy_version', 40);
            $table->json('quantitative_tables')->nullable();
            $table->json('narrative_text_sections')->nullable();
            $table->json('sources')->nullable();
            $table->timestamp('populated_at')->nullable();
            $this->audit($table);

            $table->unique('dossier_id');
        });
    }

    private function depositPackages(): void
    {
        if (Schema::hasTable('sfs_deposit_packages')) {
            return;
        }

        Schema::create('sfs_deposit_packages', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('dossier_id')->constrained('sfs_dossiers')->cascadeOnDelete();
            $table->uuid('generated_xbrl_instance_file_id')->nullable();
            $table->json('tebeni_validation_report')->nullable();
            $table->string('validation_status', 12)->default('not_run');
            $table->uuid('management_report_pdf_a_id')->nullable();
            $table->uuid('shareholders_minutes_pdf_a_id')->nullable();
            $table->uuid('board_minutes_pdf_a_id')->nullable();
            $table->uuid('dossier_pdf_a_id')->nullable();
            $table->json('financial_ratios_crisi_impresa')->nullable();
            $table->timestamp('exported_at')->nullable();
            $this->audit($table);

            $table->unique('dossier_id');
        });
    }

    private function artifacts(): void
    {
        if (Schema::hasTable('sfs_artifacts')) {
            return;
        }

        Schema::create('sfs_artifacts', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('dossier_id')->constrained('sfs_dossiers')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('file_name', 160);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64);
            $table->string('pdfa_conformance', 12)->nullable();
            $table->json('verification')->nullable();
            $this->audit($table);

            $table->index(['dossier_id', 'kind']);
        });
    }
};
