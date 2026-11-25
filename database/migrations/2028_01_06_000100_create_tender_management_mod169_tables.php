<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 169 — Public and private tender management (simap.ch LAPub / IVöB, ANAC / MePA D.Lgs. 36/2023): tender notices,
 * participation dossiers with the three envelopes, the company qualification vault, competitor benchmarks and the alert log.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->notices();
        $this->vaultDocuments();
        $this->participations();
        $this->benchmarks();
        $this->alerts();
    }

    public function down(): void
    {
        foreach (['tnd_alerts', 'tnd_competitor_benchmarks', 'tnd_participations', 'tnd_vault_documents', 'tnd_notices'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function notices(): void
    {
        if (Schema::hasTable('tnd_notices')) {
            return;
        }

        Schema::create('tnd_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('jurisdiction', 40); // CH_SIMAP_LAPub, IT_Dlgs36_2023_ANAC, EU_TED, PrivateRfp
            $table->string('procedure_type', 30)->default('open'); // open, selective, invitation, direct_award
            $table->string('contracting_authority_name', 200);
            $table->uuid('contracting_authority_contact_id')->nullable(); // the contact invoiced after the award
            $table->string('simap_project_number', 60)->nullable();
            $table->string('cig_code_it', 10)->nullable();
            $table->string('cup_code_it', 15)->nullable();
            $table->string('rup_name', 160)->nullable();
            $table->string('dedicated_bank_account_it', 34)->nullable(); // conto dedicato L. 136/2010
            $table->json('cpv_category_codes')->nullable();
            $table->string('award_criterion', 40)->default('LowestPrice'); // OEPV_MostEconomicallyAdvantageous, LowestPrice
            $table->string('formula_type', 20)->nullable(); // Linear, Bilinear, Interpolation
            $table->string('currency', 3)->default('EUR');
            $table->decimal('base_auction_amount', 18, 2)->default(0);
            $table->decimal('non_subject_safety_costs_amount', 18, 2)->default(0);
            $table->decimal('estimated_labor_cost_amount', 18, 2)->default(0);
            $table->decimal('technical_max_score', 8, 2)->nullable();
            $table->decimal('economic_max_score', 8, 2)->nullable();
            $table->json('required_soa_categories')->nullable(); // [{category: OG1, classification: III}]
            $table->decimal('required_min_turnover', 18, 2)->nullable(); // specific turnover over the reference years
            $table->unsignedSmallInteger('turnover_reference_years')->default(3);
            $table->unsignedSmallInteger('required_similar_projects_count')->default(0);
            $table->decimal('required_similar_projects_amount', 18, 2)->nullable();
            $table->json('required_certifications')->nullable(); // vault document types
            $table->date('publication_date')->nullable();
            $table->dateTime('clarification_deadline_at')->nullable();
            $table->dateTime('submission_peremptory_deadline_at')->nullable();
            $table->string('status', 20)->default('open'); // open, closed, awarded, cancelled
            $this->audit($table);

            $table->index(['tenant_id', 'jurisdiction', 'status']);
            $table->index(['tenant_id', 'submission_peremptory_deadline_at']);
        });
    }

    private function vaultDocuments(): void
    {
        if (Schema::hasTable('tnd_vault_documents')) {
            return;
        }

        Schema::create('tnd_vault_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('doc_type', 60);
            $table->string('title', 200)->nullable();
            $table->string('document_number', 80)->nullable();
            $table->string('issuing_body', 160)->nullable();
            $table->json('soa_categories_classifications')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('intermediate_triennial_check_date')->nullable();
            $table->boolean('fvoe20_synced')->default(false);
            $table->dateTime('fvoe20_synced_at')->nullable();
            $table->decimal('numeric_value', 12, 2)->nullable(); // e.g. Logib deviation percent
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->string('verification_status', 20)->default('Valid'); // Valid, ExpiringSoon, Expired, PendingRenewal
            $table->uuid('renewed_from_id')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'doc_type']);
            $table->index(['tenant_id', 'verification_status', 'expiry_date']);
        });
    }

    private function participations(): void
    {
        if (Schema::hasTable('tnd_participations')) {
            return;
        }

        Schema::create('tnd_participations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('tender_id')->constrained('tnd_notices')->cascadeOnDelete();
            $table->string('participation_mode', 40)->default('SingleCompany'); // SingleCompany, RTI_Consorzio_Mandataria_Mandante, Avvalimento
            $table->decimal('rti_quota_percentage', 6, 2)->nullable();
            $table->json('partner_companies')->nullable();
            $table->json('go_no_go_score')->nullable();
            $table->uuid('provisional_bid_bond_id')->nullable(); // Mod 147 provisional guarantee (reference, no foreign key)
            $table->decimal('bid_bond_base_percentage', 5, 2)->nullable();
            $table->json('bid_bond_applied_reductions')->nullable();
            $table->decimal('bid_bond_final_amount', 18, 2)->nullable();
            $table->string('anac_contribution_receipt_id', 80)->nullable();
            $table->decimal('anac_contribution_amount', 18, 2)->nullable();
            $table->json('administrative_envelope_docs')->nullable();
            $table->json('technical_envelope_docs')->nullable();
            $table->decimal('technical_envelope_score_target', 8, 2)->nullable();
            $table->json('economic_envelope_docs')->nullable();
            $table->decimal('economic_discount_percentage_offered', 8, 4)->nullable();
            $table->decimal('offered_amount', 18, 2)->nullable();
            $table->decimal('estimated_total_cost', 18, 2)->nullable();
            $table->decimal('net_margin_amount', 18, 2)->nullable();
            $table->decimal('company_safety_costs_declared', 18, 2)->nullable(); // Art. 108 co. 9
            $table->decimal('company_labor_costs_declared', 18, 2)->nullable(); // Art. 108 co. 9
            $table->json('offer_simulation')->nullable();
            $table->json('edgue_data')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('status', 30)->default('Scouting'); // Scouting, GoApproved, PreparingEnvelopes, Submitted, AwardedWon, Lost, LegalAppeal_Ricorso
            $table->uuid('converted_contract_id')->nullable(); // Mod 108 contract
            $table->uuid('converted_project_job_id')->nullable(); // Mod 065 project
            $table->dateTime('converted_at')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'tender_id']);
        });
    }

    private function benchmarks(): void
    {
        if (Schema::hasTable('tnd_competitor_benchmarks')) {
            return;
        }

        Schema::create('tnd_competitor_benchmarks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('tender_id')->nullable();
            $table->string('competitor_name', 200);
            $table->string('contracting_authority_name', 200);
            $table->string('cpv_code', 12)->nullable();
            $table->string('jurisdiction', 40)->nullable();
            $table->date('award_date')->nullable();
            $table->decimal('base_auction_amount', 18, 2)->nullable();
            $table->decimal('awarded_amount', 18, 2)->nullable();
            $table->decimal('offered_discount_percentage', 8, 4)->nullable();
            $table->decimal('technical_score', 8, 2)->nullable();
            $table->decimal('economic_score', 8, 2)->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->boolean('is_winner')->default(false);
            $table->string('source', 120)->nullable(); // simap.ch, ANAC, accesso agli atti
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'contracting_authority_name']);
            $table->index(['tenant_id', 'cpv_code']);
        });
    }

    private function alerts(): void
    {
        if (Schema::hasTable('tnd_alerts')) {
            return;
        }

        Schema::create('tnd_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_type', 20); // notice, vault_document
            $table->uuid('subject_id');
            $table->string('alert_type', 40); // submission_deadline, clarification_deadline, vault_expiry, soa_milestone, betreibungsauszug_age
            $table->string('milestone', 20); // e.g. -168h, -72h, -24h, -4h, 90d, 0d
            $table->string('severity', 12)->default('warning'); // info, warning, critical
            $table->string('message_key', 120);
            $table->json('message_params')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('raised_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'subject_id', 'alert_type', 'milestone'], 'uq_tnd_alert_milestone');
            $table->index(['tenant_id', 'acknowledged_at']);
        });
    }
};
