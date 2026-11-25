<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 233 (Issue #479) — Van Selling (Tentata Vendita su Furgone) & Offline Field Sales:
 * MobileVanWarehouse, DailyVanLoadManifest, FieldRoutePlan, RouteVisitStop / VanVisitOutcome,
 * and EndOfDayVanSettlement.
 *
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createVanWarehouses();
        $this->createVanLoadManifests();
        $this->createFieldRoutePlans();
        $this->createVanVisitOutcomes();
        $this->createVanEodSettlements();
    }

    public function down(): void
    {
        Schema::dropIfExists('van_eod_settlements');
        Schema::dropIfExists('van_visit_outcomes');
        Schema::dropIfExists('field_route_plans');
        Schema::dropIfExists('van_load_manifests');
        Schema::dropIfExists('van_warehouses');
    }

    private function createVanWarehouses(): void
    {
        if (Schema::hasTable('van_warehouses')) {
            return;
        }

        Schema::create('van_warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('license_plate', 32);
            $table->string('vehicle_make_model', 120)->nullable();
            $table->uuid('driver_user_id')->nullable()->index();
            $table->string('driver_name', 140)->nullable();
            $table->uuid('depot_warehouse_id')->nullable()->index();
            $table->boolean('has_cold_chain')->default(false);
            $table->decimal('min_temp_celsius', 5, 2)->nullable();
            $table->decimal('max_temp_celsius', 5, 2)->nullable();
            $table->decimal('current_temp_celsius', 5, 2)->nullable();
            $table->timestamp('last_temp_recorded_at')->nullable();
            $table->boolean('temp_alert_active')->default(false);
            $table->string('invoice_sectional_code', 32);
            $table->unsignedInteger('invoice_next_number')->default(1);
            $table->string('ddt_sectional_code', 32);
            $table->unsignedInteger('ddt_next_number')->default(1);
            $table->string('receipt_sectional_code', 32);
            $table->unsignedInteger('receipt_next_number')->default(1);
            $table->unsignedSmallInteger('fiscal_year')->default(2026);
            $table->string('printer_profile', 32)->default('escpos_80mm');
            $table->string('printer_brand', 40)->default('Zebra');
            $table->string('printer_mac_address', 40)->nullable();
            $table->string('status', 30)->default('active');
            $table->json('current_stock_snapshot')->nullable();
            $table->json('empties_onboard_snapshot')->nullable();
            $table->json('temperature_log')->nullable();
            $table->unsignedBigInteger('sync_version')->default(1);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'license_plate']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    private function createVanLoadManifests(): void
    {
        if (Schema::hasTable('van_load_manifests')) {
            return;
        }

        Schema::create('van_load_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('van_warehouse_id')->constrained('van_warehouses')->cascadeOnDelete();
            $table->string('manifest_number', 48);
            $table->date('load_date');
            $table->unsignedTinyInteger('weekday_iso');
            $table->string('route_code', 40)->nullable();
            $table->uuid('depot_warehouse_id')->nullable();
            $table->uuid('global_ddt_id')->nullable()->index();
            $table->string('global_ddt_number', 48)->nullable();
            $table->string('global_ddt_causal', 80)->default('Tentata Vendita');
            $table->string('regulatory_reference', 120)->default('DPR 472/1996 & CM 249/E');
            $table->string('self_addressed_company_name', 180)->nullable();
            $table->decimal('departure_temp_celsius', 5, 2)->nullable();
            $table->boolean('cold_chain_compliant')->default(true);
            $table->string('status', 30)->default('planned');
            $table->json('lines');
            $table->json('replenishment_suggestions')->nullable();
            $table->json('empties_loaded')->nullable();
            $table->unsignedInteger('total_packages')->default(0);
            $table->decimal('total_gross_weight_kg', 12, 3)->default(0);
            $table->decimal('total_net_value', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'manifest_number']);
            $table->index(['tenant_id', 'load_date']);
            $table->index(['tenant_id', 'van_warehouse_id', 'status']);
        });
    }

    private function createFieldRoutePlans(): void
    {
        if (Schema::hasTable('field_route_plans')) {
            return;
        }

        Schema::create('field_route_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('van_warehouse_id')->constrained('van_warehouses')->cascadeOnDelete();
            $table->uuid('van_load_manifest_id')->nullable()->index();
            $table->string('route_code', 40);
            $table->string('name', 160);
            $table->date('plan_date');
            $table->unsignedTinyInteger('weekday_iso');
            $table->boolean('is_recurring')->default(true);
            $table->uuid('salesperson_user_id')->nullable();
            $table->string('salesperson_name', 140)->nullable();
            $table->string('territory_zone', 100)->nullable();
            $table->string('country', 2)->default('IT');
            $table->string('status', 30)->default('planned');
            $table->json('stops');
            $table->unsignedSmallInteger('total_stops')->default(0);
            $table->unsignedSmallInteger('completed_stops')->default(0);
            $table->unsignedBigInteger('sync_version')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'route_code', 'plan_date']);
            $table->index(['tenant_id', 'plan_date']);
            $table->index(['tenant_id', 'van_warehouse_id']);
        });
    }

    private function createVanVisitOutcomes(): void
    {
        if (Schema::hasTable('van_visit_outcomes')) {
            return;
        }

        Schema::create('van_visit_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('field_route_plan_id')->constrained('field_route_plans')->cascadeOnDelete();
            $table->foreignUuid('van_warehouse_id')->constrained('van_warehouses')->cascadeOnDelete();
            $table->uuid('van_load_manifest_id')->nullable()->index();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('stop_sequence')->default(1);
            $table->string('visit_mode', 30)->default('van_selling');
            $table->string('outcome_status', 40);
            $table->string('no_sale_reason_code', 40)->nullable();
            $table->date('rescheduled_to')->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('fiscal_sectional_code', 32)->nullable();
            $table->string('definitive_document_number', 64)->nullable();
            $table->uuid('invoice_id')->nullable()->index();
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->string('sdi_transmission_status', 30)->default('not_applicable');
            $table->boolean('qr_bill_generated')->default(false);
            $table->string('qr_bill_reference', 64)->nullable();
            $table->boolean('credit_check_passed')->default(true);
            $table->decimal('credit_limit_amount', 15, 2)->default(0);
            $table->decimal('exposure_before_visit', 15, 2)->default(0);
            $table->decimal('overdue_amount_before_visit', 15, 2)->default(0);
            $table->boolean('credit_blocked_initially')->default(false);
            $table->boolean('unblocked_by_collection')->default(false);
            $table->uuid('unblocking_payment_id')->nullable();
            $table->decimal('collected_overdue_amount', 15, 2)->default(0);
            $table->json('sale_lines')->nullable();
            $table->json('return_lines')->nullable();
            $table->json('empties_movements')->nullable();
            $table->json('collections')->nullable();
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('empties_net_deposit_amount', 15, 2)->default(0);
            $table->decimal('returns_deduction_amount', 15, 2)->default(0);
            $table->decimal('total_collected_amount', 15, 2)->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('printer_format', 30)->default('escpos_80mm');
            $table->text('thermal_print_payload')->nullable();
            $table->string('client_idempotency_key', 100)->nullable();
            $table->timestamp('offline_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedBigInteger('sync_version')->default(1);
            $table->text('sync_conflict_notes')->nullable();
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->timestamp('visited_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'client_idempotency_key']);
            $table->index(['tenant_id', 'van_warehouse_id', 'visited_at']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'outcome_status']);
        });
    }

    private function createVanEodSettlements(): void
    {
        if (Schema::hasTable('van_eod_settlements')) {
            return;
        }

        Schema::create('van_eod_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('van_warehouse_id')->constrained('van_warehouses')->cascadeOnDelete();
            $table->uuid('van_load_manifest_id')->nullable()->index();
            $table->uuid('field_route_plan_id')->nullable()->index();
            $table->string('settlement_number', 48);
            $table->date('settlement_date');
            $table->string('status', 30)->default('draft');
            $table->json('stock_reconciliation_lines');
            $table->decimal('total_loaded_qty', 14, 4)->default(0);
            $table->decimal('total_sold_qty', 14, 4)->default(0);
            $table->decimal('total_returned_damaged_qty', 14, 4)->default(0);
            $table->decimal('total_freebies_qty', 14, 4)->default(0);
            $table->decimal('total_remaining_qty', 14, 4)->default(0);
            $table->decimal('total_keep_onboard_qty', 14, 4)->default(0);
            $table->decimal('total_unload_central_qty', 14, 4)->default(0);
            $table->json('empties_reconciliation')->nullable();
            $table->json('cold_chain_summary')->nullable();
            $table->decimal('opening_cash_float', 15, 2)->default(0);
            $table->decimal('collected_cash_amount', 15, 2)->default(0);
            $table->decimal('collected_pos_card_amount', 15, 2)->default(0);
            $table->decimal('collected_twint_amount', 15, 2)->default(0);
            $table->decimal('collected_cheque_amount', 15, 2)->default(0);
            $table->decimal('collected_bank_transfer_amount', 15, 2)->default(0);
            $table->decimal('expected_cash_remittance', 15, 2)->default(0);
            $table->decimal('counted_cash_remittance', 15, 2)->default(0);
            $table->decimal('cash_discrepancy_amount', 15, 2)->default(0);
            $table->decimal('total_remittance_amount', 15, 2)->default(0);
            $table->string('deposit_slip_number', 60)->nullable();
            $table->boolean('treasury_deposited')->default(false);
            $table->boolean('central_warehouse_unloaded')->default(false);
            $table->string('currency', 3)->default('EUR');
            $table->uuid('reconciled_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'settlement_number']);
            $table->index(['tenant_id', 'settlement_date']);
            $table->index(['tenant_id', 'van_warehouse_id']);
        });
    }
};
