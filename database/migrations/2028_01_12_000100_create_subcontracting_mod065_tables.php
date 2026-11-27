<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 065 — Lavoro conto terzi (conto lavoro passivo e attivo): orders, planned/actual component lines and the immutable
 * statutory ledger of the goods held by subcontractors (Art. 53 DPR 633/72, DPR 441/1997). Dialect-agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->orders();
        $this->components();
        $this->ledger();
    }

    public function down(): void
    {
        foreach (['sct_material_ledger_entries', 'sct_order_components', 'sct_orders'] as $table) {
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

    private function orders(): void
    {
        if (Schema::hasTable('sct_orders')) {
            return;
        }

        Schema::create('sct_orders', function (Blueprint $table): void {
            $this->base($table);
            $table->string('order_number', 40);
            $table->string('direction', 20); // passive (to a vendor), active (for a customer)
            $table->string('subcontracting_type', 30); // full_bom, single_operation
            $table->string('status', 30)->default('draft')->index();
            $table->string('partner_type', 20); // supplier, contact
            $table->uuid('partner_id')->index();
            $table->string('partner_name', 200)->nullable();
            $table->uuid('source_warehouse_id')->nullable();
            $table->uuid('subcontractor_warehouse_id')->nullable();
            $table->uuid('bom_id')->nullable();
            $table->uuid('linked_production_order_id')->nullable()->index();
            $table->uuid('linked_production_order_operation_id')->nullable();
            $table->string('routing_operation_label', 200)->nullable();
            $table->uuid('linked_service_purchase_order_id')->nullable();
            $table->uuid('linked_customer_sales_order_id')->nullable();
            $table->uuid('outgoing_material_ddt_id')->nullable();
            $table->string('inbound_return_document_type', 30)->nullable(); // delivery_note, purchase_receipt
            $table->uuid('inbound_return_document_id')->nullable();
            $table->string('inbound_return_document_number', 60)->nullable();
            $table->uuid('output_product_id');
            $table->decimal('planned_quantity', 16, 4);
            $table->decimal('received_quantity', 16, 4)->default(0);
            $table->decimal('scrap_quantity', 16, 4)->default(0);
            $table->decimal('delivered_quantity', 16, 4)->default(0); // active: finished goods already returned to the customer
            $table->decimal('unit_service_cost', 16, 4)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->date('expected_return_date')->nullable();
            $table->string('customs_regime', 30)->default('none'); // none, tpp_outward_processing, tpa_inward_processing
            $table->uuid('customs_declaration_id')->nullable();
            $table->decimal('capitalized_service_amount', 16, 4)->default(0);
            $table->decimal('capitalized_unit_cost', 16, 6)->default(0);
            $table->jsonb('service_billing')->nullable(); // active: the basis of the transformation service invoice
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'direction', 'status']);
        });
    }

    private function components(): void
    {
        if (Schema::hasTable('sct_order_components')) {
            return;
        }

        Schema::create('sct_order_components', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('subcontracting_order_id')->index()->constrained('sct_orders')->cascadeOnDelete();
            $table->uuid('component_product_id')->index();
            $table->string('unit_of_measure', 20)->default('PCS');
            $table->decimal('required_qty_per_unit', 16, 6);
            $table->decimal('total_planned_qty', 16, 4);
            $table->decimal('dispatched_qty', 16, 4)->default(0);
            $table->decimal('consumed_qty', 16, 4)->default(0);
            $table->decimal('scrap_qty', 16, 4)->default(0);
            $table->decimal('returned_unused_qty', 16, 4)->default(0);
            $table->uuid('lot_id')->nullable();
            $table->string('serial_number', 100)->nullable();
            $this->audit($table);
        });
    }

    private function ledger(): void
    {
        if (Schema::hasTable('sct_material_ledger_entries')) {
            return;
        }

        Schema::create('sct_material_ledger_entries', function (Blueprint $table): void {
            $this->base($table);
            $table->string('direction', 20); // passive, active
            $table->uuid('subcontracting_order_id')->index();
            $table->uuid('partner_id')->index();
            $table->uuid('virtual_warehouse_id')->nullable()->index();
            $table->uuid('component_product_id')->index();
            $table->uuid('lot_id')->nullable();
            $table->string('movement_type', 40);
            $table->date('entry_date');
            $table->decimal('dispatched_qty', 16, 4)->default(0);
            $table->decimal('consumed_in_returned_goods_qty', 16, 4)->default(0);
            $table->decimal('scrap_reported_by_subcontractor_qty', 16, 4)->default(0);
            $table->decimal('returned_unused_qty', 16, 4)->default(0);
            $table->decimal('remaining_at_subcontractor_qty', 16, 4)->default(0);
            $table->uuid('ddt_document_id')->nullable();
            $table->string('ddt_number', 60)->nullable();
            $table->date('ddt_date')->nullable();
            $table->string('transport_reason', 60)->nullable();
            $table->date('statutory_return_deadline')->nullable()->index();
            $table->uuid('customs_declaration_id')->nullable();
            $table->decimal('valuation_amount', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'direction', 'entry_date']);
            $table->index(['subcontracting_order_id', 'component_product_id']);
        });
    }
};
