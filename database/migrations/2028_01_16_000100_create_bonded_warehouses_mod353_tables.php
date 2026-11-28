<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #599 (Mod 353) — private customs warehouses (CDU 7100 / Swiss OZL and Zollfreilager), IVA warehouses (Art. 50-bis DL 331/93)
 * and AEO compliance: the warehouse authorizations with the comprehensive guarantee (CGU), the bonded stock ledger per MRN, the immutable
 * discharge trail, the append-only CGU ledger, the telematic load / unload register of the IVA warehouse and the AEO audit log.
 * Tenant isolation is the application level TenantScope (tenant_id on every row), like every other table. Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->authorizations();
        $this->mrnLots();
        $this->dischargeMovements();
        $this->cguLedger();
        $this->vatDepotRegisters();
        $this->aeoAudits();
        $this->flagStockLots();
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_lots', 'customs_status')) {
            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->dropColumn('customs_status');
            });
        }

        foreach (['aeo_compliance_audits', 'vat_depot_50bis_registers', 'customs_cgu_guarantee_ledger', 'customs_discharge_movements', 'customs_bonded_mrn_lots', 'customs_warehouse_authorizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function authorizations(): void
    {
        if (Schema::hasTable('customs_warehouse_authorizations')) {
            return;
        }

        Schema::create('customs_warehouse_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('authorization_code', 60);
            $table->string('customs_office_code', 30);
            $table->string('regime_type', 30);
            $table->string('warehouse_subtype', 20);
            $table->string('cgu_guarantee_reference', 80)->nullable();
            $table->decimal('cgu_max_ceiling_amount', 16, 2)->default(0);
            $table->decimal('cgu_utilized_amount', 16, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('aeo_certificate_number', 60)->nullable();
            $table->string('aeo_status', 10)->default('None');
            $table->date('aeo_next_audit_date')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'authorization_code'], 'cwa_code_uq');
            $table->index(['tenant_id', 'regime_type', 'is_active'], 'cwa_regime_idx');
            $table->index(['tenant_id', 'warehouse_id'], 'cwa_warehouse_idx');
        });
    }

    private function mrnLots(): void
    {
        if (Schema::hasTable('customs_bonded_mrn_lots')) {
            return;
        }

        Schema::create('customs_bonded_mrn_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_id')->constrained('customs_warehouse_authorizations')->restrictOnDelete();
            $table->string('mrn_number', 18);
            $table->unsignedSmallInteger('mrn_item_number')->default(1);
            $table->string('declaration_type', 20);
            $table->date('entry_date');
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->uuid('wms_lot_id')->nullable()->index();
            $table->string('taric_hs_code', 10);
            $table->string('country_of_origin', 2);
            $table->boolean('preferential_origin')->default(false);
            $table->string('preferential_agreement_code', 30)->nullable();
            $table->decimal('initial_quantity', 16, 4);
            $table->decimal('remaining_quantity', 16, 4);
            $table->string('unit_of_measure', 10)->default('EA');
            $table->decimal('initial_net_weight_kg', 16, 3)->default(0);
            $table->decimal('remaining_net_weight_kg', 16, 3)->default(0);
            $table->decimal('initial_gross_weight_kg', 16, 3)->default(0);
            $table->decimal('remaining_gross_weight_kg', 16, 3)->default(0);
            $table->decimal('customs_statistical_value', 16, 2)->default(0);
            $table->decimal('duty_rate_percent', 7, 4)->default(0);
            $table->decimal('vat_rate_percent', 7, 4)->default(0);
            $table->decimal('suspended_duty_amount', 16, 2)->default(0);
            $table->decimal('suspended_vat_amount', 16, 2)->default(0);
            $table->decimal('cgu_engaged_amount', 16, 2)->default(0);
            $table->string('customs_status', 30)->default('NonUnion_Suspended');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'mrn_number', 'mrn_item_number'], 'cbml_mrn_item_uq');
            $table->index(['tenant_id', 'authorization_id', 'product_id', 'entry_date'], 'cbml_fifo_idx');
            $table->index(['tenant_id', 'customs_status'], 'cbml_status_idx');
            $table->index(['tenant_id', 'taric_hs_code'], 'cbml_taric_idx');
        });
    }

    private function dischargeMovements(): void
    {
        if (Schema::hasTable('customs_discharge_movements')) {
            return;
        }

        Schema::create('customs_discharge_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_id')->constrained('customs_warehouse_authorizations')->restrictOnDelete();
            $table->foreignUuid('mrn_lot_id')->constrained('customs_bonded_mrn_lots')->restrictOnDelete();
            $table->string('discharge_type', 30);
            $table->string('discharge_mrn_number', 18)->nullable();
            $table->date('discharge_date');
            $table->string('allocation_method', 20);
            $table->decimal('discharged_quantity', 16, 4);
            $table->decimal('discharged_net_weight_kg', 16, 3)->default(0);
            $table->decimal('released_customs_value', 16, 2)->default(0);
            $table->decimal('paid_duty_amount', 16, 2)->default(0);
            $table->decimal('paid_vat_amount', 16, 2)->default(0);
            $table->decimal('extinguished_duty_amount', 16, 2)->default(0);
            $table->decimal('extinguished_vat_amount', 16, 2)->default(0);
            $table->decimal('cgu_released_amount', 16, 2)->default(0);
            $table->string('usual_handling_code', 40)->nullable();
            $table->text('usual_handling_notes')->nullable();
            $table->uuid('customs_prospectus_document_id')->nullable()->index();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'mrn_lot_id', 'discharge_date'], 'cdm_lot_idx');
            $table->index(['tenant_id', 'authorization_id', 'discharge_type'], 'cdm_type_idx');
        });
    }

    private function cguLedger(): void
    {
        if (Schema::hasTable('customs_cgu_guarantee_ledger')) {
            return;
        }

        Schema::create('customs_cgu_guarantee_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_id')->constrained('customs_warehouse_authorizations')->restrictOnDelete();
            $table->string('reference_type', 30);
            $table->uuid('reference_id')->nullable();
            $table->string('movement_direction', 10);
            $table->decimal('duty_amount', 16, 2)->default(0);
            $table->decimal('vat_amount', 16, 2)->default(0);
            $table->decimal('total_cgu_delta', 16, 2);
            $table->decimal('running_utilized_balance', 16, 2);
            $table->decimal('remaining_plafond', 16, 2);
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'authorization_id', 'recorded_at'], 'ccgl_auth_idx');
            $table->index(['reference_type', 'reference_id'], 'ccgl_ref_idx');
        });
    }

    private function vatDepotRegisters(): void
    {
        if (Schema::hasTable('vat_depot_50bis_registers')) {
            return;
        }

        Schema::create('vat_depot_50bis_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_id')->constrained('customs_warehouse_authorizations')->restrictOnDelete();
            $table->unsignedBigInteger('register_progressive_number');
            $table->string('movement_type', 30);
            $table->date('movement_date');
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('lot_reference', 100)->nullable();
            $table->decimal('quantity', 16, 4);
            $table->decimal('base_goods_value', 16, 2)->default(0);
            $table->decimal('storage_and_processing_services_value', 16, 2)->default(0);
            $table->decimal('total_taxable_extraction_base', 16, 2)->default(0);
            $table->decimal('vat_rate_percent', 7, 4)->default(0);
            $table->decimal('vat_amount', 16, 2)->default(0);
            $table->foreignUuid('self_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('sdi_document_type', 10)->default('None');
            $table->string('f24_payment_reference', 60)->nullable();
            $table->date('f24_payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'authorization_id', 'register_progressive_number'], 'vd50_progressive_uq');
            $table->index(['tenant_id', 'authorization_id', 'movement_date'], 'vd50_date_idx');
            $table->index(['tenant_id', 'product_id', 'lot_reference'], 'vd50_stock_idx');
        });
    }

    private function aeoAudits(): void
    {
        if (Schema::hasTable('aeo_compliance_audits')) {
            return;
        }

        Schema::create('aeo_compliance_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('authorization_id')->constrained('customs_warehouse_authorizations')->restrictOnDelete();
            $table->string('audit_type', 40);
            $table->date('audit_date');
            $table->foreignUuid('mrn_lot_id')->nullable()->constrained('customs_bonded_mrn_lots')->nullOnDelete();
            $table->uuid('product_id')->nullable()->index();
            $table->decimal('wms_physical_quantity', 16, 4)->default(0);
            $table->decimal('customs_ledger_quantity', 16, 4)->default(0);
            $table->decimal('discrepancy_quantity', 16, 4)->default(0);
            $table->string('discrepancy_status', 30)->default('Matched');
            $table->string('partner_or_carrier_name', 190)->nullable();
            $table->string('partner_aeo_status', 10)->nullable();
            $table->boolean('security_seal_verified')->default(false);
            $table->text('findings')->nullable();
            $table->text('corrective_action')->nullable();
            $table->uuid('auditor_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'authorization_id', 'audit_date'], 'aca_auth_idx');
            $table->index(['tenant_id', 'audit_type', 'discrepancy_status'], 'aca_status_idx');
        });
    }

    /**
     * The WMS lot shows whether its stock is still under customs supervision (NonUnion_Suspended ...).
     */
    private function flagStockLots(): void
    {
        if (Schema::hasTable('stock_lots') && ! Schema::hasColumn('stock_lots', 'customs_status')) {
            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->string('customs_status', 30)->nullable()->index();
            });
        }
    }
};
