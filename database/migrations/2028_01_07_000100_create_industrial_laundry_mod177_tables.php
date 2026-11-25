<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 177 — industrial laundries and linen / workwear rental: tagged garments (RFID UHF EPC), roll cages (returnable containers),
 * par stock contracts, tunnel gate scan sessions, UNI EN 14065 RABC wash batches and the monthly wash + rental billing runs.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Every table carries the tenant, soft deletes and the audit columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rollCages();
        $this->garments();
        $this->parContracts();
        $this->parContractLines();
        $this->scanSessions();
        $this->garmentEvents();
        $this->washPrograms();
        $this->washBatches();
        $this->washBatchSessions();
        $this->washBatchGarments();
        $this->billingRuns();
        $this->billingRunLines();
    }

    public function down(): void
    {
        foreach (['ilr_billing_run_lines', 'ilr_billing_runs', 'ilr_wash_batch_garments', 'ilr_wash_batch_sessions', 'ilr_wash_batches', 'ilr_wash_programs', 'ilr_garment_events', 'ilr_scan_sessions', 'ilr_par_contract_lines', 'ilr_par_contracts', 'ilr_garments', 'ilr_roll_cages'] as $table) {
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

    private function rollCages(): void
    {
        if (Schema::hasTable('ilr_roll_cages')) {
            return;
        }

        Schema::create('ilr_roll_cages', function (Blueprint $table): void {
            $this->base($table);
            $table->string('barcode', 60);
            $table->string('label', 160)->nullable();
            $table->decimal('tare_weight_kg', 10, 3)->default(0);
            $table->decimal('max_gross_weight_kg', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('holder_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamp('last_scanned_at')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'holder_contact_id']);
        });
    }

    private function garments(): void
    {
        if (Schema::hasTable('ilr_garments')) {
            return;
        }

        Schema::create('ilr_garments', function (Blueprint $table): void {
            $this->base($table);
            $table->string('epc_uid_rfid_hex', 64);
            $table->string('thermal_barcode', 60)->nullable();
            $table->string('article_type', 32);
            $table->uuid('product_id')->nullable();
            $table->string('description', 160)->nullable();
            $table->foreignUuid('assigned_customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('assigned_wearer_employee_name', 160)->nullable();
            $table->string('assigned_locker_code', 40)->nullable();
            $table->unsignedInteger('wash_cycles_count')->default(0);
            $table->unsignedInteger('max_certified_wash_cycles_limit')->nullable();
            $table->string('ppe_certification_standard', 60)->nullable();
            $table->unsignedSmallInteger('ppe_category')->nullable();
            $table->string('current_lifecycle_state', 24)->default('clean_in_stock');
            $table->boolean('is_reissue_blocked')->default(false);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_soiled_at')->nullable();
            $table->timestamp('condemned_at')->nullable();
            $table->timestamp('unreturned_billed_at')->nullable();
            $table->string('mending_or_condemn_reason', 60)->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'epc_uid_rfid_hex']);
            $table->index(['tenant_id', 'assigned_customer_id', 'current_lifecycle_state'], 'ilr_garments_customer_state_idx');
            $table->index(['tenant_id', 'article_type']);
            $table->index(['tenant_id', 'assigned_locker_code']);
        });
    }

    private function parContracts(): void
    {
        if (Schema::hasTable('ilr_par_contracts')) {
            return;
        }

        Schema::create('ilr_par_contracts', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('customer_segment', 16)->default('hotel');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('billing_model', 40)->default('per_piece_washed');
            $table->string('kg_basis', 8)->default('clean');
            $table->decimal('unreturned_garment_replacement_price', 15, 4)->default(0);
            $table->unsignedSmallInteger('unreturned_threshold_days')->default(60);
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('tax_rate', 7, 4)->default(22);
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'customer_id', 'is_active']);
        });
    }

    private function parContractLines(): void
    {
        if (Schema::hasTable('ilr_par_contract_lines')) {
            return;
        }

        Schema::create('ilr_par_contract_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('ilr_par_contracts')->cascadeOnDelete();
            $table->string('article_type', 32);
            $table->uuid('product_id')->nullable();
            $table->string('label', 160)->nullable();
            $table->unsignedInteger('circulating_stock_qty')->default(0);
            $table->decimal('weekly_min_billing_percentage', 7, 4)->default(0);
            $table->decimal('seasonal_min_billing_percentage', 7, 4)->nullable();
            $table->unsignedSmallInteger('season_start_month')->nullable();
            $table->unsignedSmallInteger('season_end_month')->nullable();
            $table->decimal('unit_wash_price', 15, 4)->default(0);
            $table->decimal('price_per_kg', 15, 4)->default(0);
            $table->decimal('rental_price', 15, 4)->default(0);
            $table->decimal('replacement_price', 15, 4)->default(0);
            $this->audit($table);

            $table->unique(['contract_id', 'article_type']);
        });
    }

    private function scanSessions(): void
    {
        if (Schema::hasTable('ilr_scan_sessions')) {
            return;
        }

        Schema::create('ilr_scan_sessions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('external_scan_id', 80)->nullable();
            $table->string('gate_antenna_id', 60);
            $table->foreignUuid('roll_cage_id')->nullable()->constrained('ilr_roll_cages')->nullOnDelete();
            $table->string('roll_cage_barcode', 60);
            $table->decimal('roll_cage_tare_weight_kg', 10, 3)->default(0);
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->decimal('net_weight_kg', 10, 3)->nullable();
            $table->string('direction', 16);
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('read_epc_tags_list')->nullable();
            $table->text('accepted_epc_tags')->nullable();
            $table->text('rejected_reads')->nullable();
            $table->text('alerts')->nullable();
            $table->unsignedInteger('raw_reads_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->uuid('generated_ddt_id')->nullable();
            $table->string('generated_ddt_number', 60)->nullable();
            $table->timestamp('scanned_at');
            $this->audit($table);

            $table->unique(['tenant_id', 'external_scan_id']);
            $table->index(['tenant_id', 'contact_id', 'direction', 'scanned_at'], 'ilr_scan_sessions_customer_idx');
        });
    }

    private function garmentEvents(): void
    {
        if (Schema::hasTable('ilr_garment_events')) {
            return;
        }

        Schema::create('ilr_garment_events', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('garment_id')->constrained('ilr_garments')->cascadeOnDelete();
            $table->foreignUuid('scan_session_id')->nullable()->constrained('ilr_scan_sessions')->nullOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('article_type', 32);
            $table->string('event_type', 32);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->unsignedInteger('wash_cycles_count')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('occurred_at');
            $this->audit($table);

            $table->index(['tenant_id', 'garment_id', 'occurred_at']);
            $table->index(['tenant_id', 'contact_id', 'event_type', 'occurred_at'], 'ilr_garment_events_billing_idx');
        });
    }

    private function washPrograms(): void
    {
        if (Schema::hasTable('ilr_wash_programs')) {
            return;
        }

        Schema::create('ilr_wash_programs', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->boolean('is_thermal')->default(true);
            $table->boolean('is_chemical')->default(false);
            $table->boolean('disinfects')->default(false);
            $table->decimal('target_temperature_c', 6, 2)->default(0);
            $table->unsignedSmallInteger('min_hold_minutes')->default(0);
            $table->decimal('min_chemical_dosing_ml_per_kg', 10, 3)->nullable();
            $table->string('disinfectant_code', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'code']);
        });
    }

    private function washBatches(): void
    {
        if (Schema::hasTable('ilr_wash_batches')) {
            return;
        }

        Schema::create('ilr_wash_batches', function (Blueprint $table): void {
            $this->base($table);
            $table->string('batch_code', 60);
            $table->string('disinfection_thermal_chem_program_code', 40);
            $table->string('rabc_risk_zone', 16)->default('standard');
            $table->string('rabc_control_status', 16)->default('pending');
            $table->decimal('target_temperature_c', 6, 2)->default(0);
            $table->unsignedSmallInteger('target_hold_minutes')->default(0);
            $table->decimal('target_chemical_dosing_ml_per_kg', 10, 3)->nullable();
            $table->decimal('measured_temperature_c', 6, 2)->nullable();
            $table->unsignedSmallInteger('measured_hold_minutes')->nullable();
            $table->decimal('measured_chemical_dosing_ml_per_kg', 10, 3)->nullable();
            $table->decimal('microbiological_cfu_per_25cm2', 10, 2)->nullable();
            $table->decimal('load_net_weight_kg', 10, 3)->default(0);
            $table->unsignedInteger('garments_count')->default(0);
            $table->string('operator_name', 160)->nullable();
            $table->string('verifier_name', 160)->nullable();
            $table->uuid('verified_by_user_id')->nullable();
            $table->text('violations')->nullable();
            $table->timestamp('washed_at')->nullable();
            $table->timestamp('certified_at')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'batch_code']);
            $table->index(['tenant_id', 'rabc_control_status']);
        });
    }

    private function washBatchSessions(): void
    {
        if (Schema::hasTable('ilr_wash_batch_sessions')) {
            return;
        }

        Schema::create('ilr_wash_batch_sessions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('wash_batch_id')->constrained('ilr_wash_batches')->cascadeOnDelete();
            $table->foreignUuid('scan_session_id')->constrained('ilr_scan_sessions')->cascadeOnDelete();
            $this->audit($table);

            $table->unique(['wash_batch_id', 'scan_session_id']);
        });
    }

    private function washBatchGarments(): void
    {
        if (Schema::hasTable('ilr_wash_batch_garments')) {
            return;
        }

        Schema::create('ilr_wash_batch_garments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('wash_batch_id')->constrained('ilr_wash_batches')->cascadeOnDelete();
            $table->foreignUuid('garment_id')->constrained('ilr_garments')->cascadeOnDelete();
            $this->audit($table);

            $table->unique(['wash_batch_id', 'garment_id']);
        });
    }

    private function billingRuns(): void
    {
        if (Schema::hasTable('ilr_billing_runs')) {
            return;
        }

        Schema::create('ilr_billing_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('contract_id')->constrained('ilr_par_contracts')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('drafted');
            $table->string('billing_model', 40);
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('washed_pieces')->default(0);
            $table->decimal('net_weight_kg', 12, 3)->default(0);
            $table->decimal('wash_amount', 15, 4)->default(0);
            $table->decimal('minimum_top_up_amount', 15, 4)->default(0);
            $table->decimal('rental_amount', 15, 4)->default(0);
            $table->decimal('unreturned_amount', 15, 4)->default(0);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->uuid('invoice_id')->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->text('compliance_report')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'contract_id', 'period_start', 'period_end'], 'ilr_billing_runs_period_uq');
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    private function billingRunLines(): void
    {
        if (Schema::hasTable('ilr_billing_run_lines')) {
            return;
        }

        Schema::create('ilr_billing_run_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('billing_run_id')->constrained('ilr_billing_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('kind', 20);
            $table->string('article_type', 32)->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->string('uom', 8)->default('PZ');
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('amount', 15, 4)->default(0);
            $table->text('detail')->nullable();
            $this->audit($table);

            $table->index(['billing_run_id', 'position']);
        });
    }
};
