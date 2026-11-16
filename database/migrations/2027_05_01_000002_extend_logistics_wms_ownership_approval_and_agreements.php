<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #158 / WMS: legal ownership on the stock ledger, approval + partial receipt + closing of transfer orders,
 * shortage/damage split on discrepancies, consignment agreements and the "declaration" naming of consumption reports.
 *
 * Portable schema-builder syntax only (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('ownership_type', 30)->default('owned');
            $table->uuid('third_party_id')->nullable();
            $table->boolean('in_transit')->default(false);
            $table->index(['tenant_id', 'ownership_type'], 'idx_stock_mov_ownership');
        });
        $this->backfillLedgerOwnership();

        Schema::table('stock_transfer_orders', function (Blueprint $table): void {
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('inter_company_invoice_id')->nullable()->index();
        });

        Schema::table('stock_transfer_order_lines', function (Blueprint $table): void {
            $table->decimal('damaged_qty', 14, 4)->default(0);
        });

        Schema::table('transfer_discrepancies', function (Blueprint $table): void {
            $table->decimal('shortage_qty', 14, 4)->default(0);
            $table->decimal('damage_qty', 14, 4)->default(0);
        });
        DB::table('transfer_discrepancies')->update(['shortage_qty' => DB::raw('discrepancy_qty')]);
        DB::table('stock_transfer_orders')->where('status', 'reconciled')->update(['status' => 'closed', 'closed_at' => now()]);

        Schema::rename('consignment_consumption_reports', 'consignment_consumption_declarations');
        Schema::table('consignment_consumption_lines', function (Blueprint $table): void {
            $table->renameColumn('report_id', 'declaration_id');
        });
        Schema::rename('consignment_consumption_lines', 'consignment_consumption_declaration_lines');
        Schema::table('consignment_consumption_declarations', function (Blueprint $table): void {
            $table->uuid('agreement_id')->nullable()->index();
            $table->string('ownership_from', 30)->nullable();
            $table->string('ownership_to', 30)->nullable();
        });

        Schema::create('consignment_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('agreement_number', 50);
            $table->string('direction', 20); // customer (we are the consignor), vendor (we hold third-party goods)
            $table->uuid('partner_id');
            $table->uuid('warehouse_id');
            $table->string('status', 20)->default('active')->index(); // active, terminated
            $table->string('billing_frequency', 20)->default('monthly'); // monthly, quarterly, on_demand
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'agreement_number'], 'uq_consignment_agreement_number');
            $table->index(['tenant_id', 'direction', 'status'], 'idx_consignment_agreement_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_agreements');

        Schema::table('consignment_consumption_declarations', function (Blueprint $table): void {
            $table->dropIndex(['agreement_id']);
            $table->dropColumn(['agreement_id', 'ownership_from', 'ownership_to']);
        });
        Schema::rename('consignment_consumption_declaration_lines', 'consignment_consumption_lines');
        Schema::table('consignment_consumption_lines', function (Blueprint $table): void {
            $table->renameColumn('declaration_id', 'report_id');
        });
        Schema::rename('consignment_consumption_declarations', 'consignment_consumption_reports');

        DB::table('stock_transfer_orders')->where('status', 'closed')->update(['status' => 'reconciled']);
        Schema::table('transfer_discrepancies', function (Blueprint $table): void {
            $table->dropColumn(['shortage_qty', 'damage_qty']);
        });
        Schema::table('stock_transfer_order_lines', function (Blueprint $table): void {
            $table->dropColumn('damaged_qty');
        });
        Schema::table('stock_transfer_orders', function (Blueprint $table): void {
            $table->dropIndex(['inter_company_invoice_id']);
            $table->dropColumn(['approved_by', 'approved_at', 'closed_at', 'inter_company_invoice_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('idx_stock_mov_ownership');
            $table->dropColumn(['ownership_type', 'third_party_id', 'in_transit']);
        });
    }

    /** Existing ledger rows inherit the ownership of the warehouse they were booked on. */
    private function backfillLedgerOwnership(): void
    {
        $byWarehouseType = ['vendor_consignment' => 'vendor_consignment', 'customer_consignment' => 'customer_consignment', 'subcontractor' => 'subcontractor_held'];
        foreach ($byWarehouseType as $warehouseType => $ownership) {
            DB::table('stock_movements')
                ->whereIn('warehouse_id', DB::table('warehouses')->where('warehouse_type', $warehouseType)->select('id'))
                ->update(['ownership_type' => $ownership]);
        }
        DB::table('stock_movements')
            ->whereIn('warehouse_id', DB::table('warehouses')->where('warehouse_type', 'transit')->select('id'))
            ->update(['in_transit' => true]);
    }
};
