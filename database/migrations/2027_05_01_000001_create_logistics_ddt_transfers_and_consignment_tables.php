<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #158 / DDT enterprise: extends the canonical `delivery_notes` table (no parallel table is created)
 * and extends the inventory transfer orders (approval, damage/shortage discrepancies, DDT and inter-company invoice link),
 * the stock ledger (legal ownership), and adds consignment agreements and consumption declarations.
 *
 * There is one transfer aggregate (`transfer_orders`, bin and lot aware) and one stock ledger (quants + `stock_movements`).
 *
 * Only portable schema-builder syntax is used so the migration runs on SQLite and PostgreSQL alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendWarehouses();
        $this->extendDeliveryNotes();
        $this->extendDeliveryNoteItems();
        $this->createDeliveryNoteInvoiceLinks();
        $this->extendStockMovements();
        $this->extendTransferOrders();
        $this->createTransferDiscrepancies();
        $this->createConsignmentTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_agreements');
        Schema::dropIfExists('consignment_consumption_declaration_lines');
        Schema::dropIfExists('consignment_consumption_declarations');
        Schema::dropIfExists('transfer_discrepancies');
        Schema::dropIfExists('delivery_note_invoice_links');

        Schema::table('transfer_order_line_lots', function (Blueprint $table): void {
            $table->dropColumn('quantity_resolved');
        });
        Schema::table('transfer_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['quantity_damaged', 'discrepancy_qty', 'discrepancy_reason']);
        });
        Schema::table('transfer_orders', function (Blueprint $table): void {
            $table->dropIndex(['delivery_note_id']);
            $table->dropIndex(['inter_company_invoice_id']);
            $table->dropColumn(['purpose', 'approved_by', 'approved_at', 'closed_at', 'delivery_note_id', 'is_inter_company', 'inter_company_invoice_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('idx_stock_mov_ownership');
            $table->dropColumn(['ownership_type', 'third_party_id', 'in_transit']);
        });

        foreach (['delivery_note_items' => ['invoiced_quantity', 'uom', 'updated_by', 'deleted_by', 'created_by'],
            'delivery_notes' => ['type', 'transport_reason_code', 'carriage_terms', 'transport_by', 'carrier_id', 'carrier_vat_number',
                'incoterms', 'net_weight_kg', 'volume_m3', 'origin_warehouse_id', 'destination_warehouse_id', 'supplier_id',
                'shipping_address_id', 'carrier_tracking_url', 'recipient_signature_name', 'currency', 'fiscal_year',
                'sequential_number', 'stock_transfer_order_id', 'issued_at', 'cancelled_at', 'updated_by', 'deleted_by'],
            'warehouses' => ['legal_owner_type', 'linked_partner_id', 'company_entity_id', 'created_by', 'updated_by', 'deleted_by'],
        ] as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function extendWarehouses(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouses', 'legal_owner_type')) {
                $table->string('legal_owner_type', 20)->default('company'); // company, vendor, customer
            }
            if (! Schema::hasColumn('warehouses', 'linked_partner_id')) {
                $table->uuid('linked_partner_id')->nullable()->index();
            }
            if (! Schema::hasColumn('warehouses', 'company_entity_id')) {
                $table->uuid('company_entity_id')->nullable()->index();
            }
            foreach (['created_by', 'updated_by', 'deleted_by'] as $audit) {
                if (! Schema::hasColumn('warehouses', $audit)) {
                    $table->uuid($audit)->nullable();
                }
            }
        });
    }

    private function extendDeliveryNotes(): void
    {
        // Internal transfers have no commercial counterparty: contact_id must become optional.
        Schema::table('delivery_notes', function (Blueprint $table): void {
            $table->uuid('contact_id')->nullable()->change();
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            $uuidColumns = ['carrier_id', 'origin_warehouse_id', 'destination_warehouse_id', 'supplier_id',
                'shipping_address_id', 'stock_transfer_order_id'];
            foreach ($uuidColumns as $column) {
                if (! Schema::hasColumn('delivery_notes', $column)) {
                    $table->uuid($column)->nullable()->index();
                }
            }
            if (! Schema::hasColumn('delivery_notes', 'type')) {
                $table->string('type', 40)->default('outbound_sale')->index();
            }
            if (! Schema::hasColumn('delivery_notes', 'transport_reason_code')) {
                $table->string('transport_reason_code', 40)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'carriage_terms')) {
                $table->string('carriage_terms', 30)->nullable(); // free, collect, free_with_charge
            }
            if (! Schema::hasColumn('delivery_notes', 'transport_by')) {
                $table->string('transport_by', 30)->nullable(); // sender, recipient, carrier
            }
            if (! Schema::hasColumn('delivery_notes', 'carrier_vat_number')) {
                $table->string('carrier_vat_number', 40)->nullable(); // P.IVA / IDI-UID CH
            }
            if (! Schema::hasColumn('delivery_notes', 'incoterms')) {
                $table->string('incoterms', 10)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'net_weight_kg')) {
                $table->decimal('net_weight_kg', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'volume_m3')) {
                $table->decimal('volume_m3', 10, 3)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'carrier_tracking_url')) {
                $table->string('carrier_tracking_url', 500)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'recipient_signature_name')) {
                $table->string('recipient_signature_name', 150)->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'currency')) {
                $table->string('currency', 3)->default('EUR');
            }
            if (! Schema::hasColumn('delivery_notes', 'fiscal_year')) {
                $table->unsignedSmallInteger('fiscal_year')->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'sequential_number')) {
                $table->unsignedInteger('sequential_number')->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }
            if (! Schema::hasColumn('delivery_notes', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            foreach (['updated_by', 'deleted_by'] as $audit) {
                if (! Schema::hasColumn('delivery_notes', $audit)) {
                    $table->uuid($audit)->nullable();
                }
            }
        });
    }

    private function extendDeliveryNoteItems(): void
    {
        Schema::table('delivery_note_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_note_items', 'invoiced_quantity')) {
                $table->decimal('invoiced_quantity', 12, 4)->default(0);
            }
            if (! Schema::hasColumn('delivery_note_items', 'uom')) {
                $table->string('uom', 20)->nullable();
            }
            foreach (['created_by', 'updated_by', 'deleted_by'] as $audit) {
                if (! Schema::hasColumn('delivery_note_items', $audit)) {
                    $table->uuid($audit)->nullable();
                }
            }
        });
    }

    private function createDeliveryNoteInvoiceLinks(): void
    {
        Schema::create('delivery_note_invoice_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('delivery_note_id')->index();
            $table->uuid('delivery_note_item_id')->index();
            $table->uuid('invoice_id')->index();
            $table->uuid('invoice_item_id')->nullable()->index();
            $table->decimal('quantity', 12, 4);
            $this->audit($table);
        });
    }

    private function extendStockMovements(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('ownership_type', 30)->default('owned');
            $table->uuid('third_party_id')->nullable();
            $table->boolean('in_transit')->default(false);
            $table->index(['tenant_id', 'ownership_type'], 'idx_stock_mov_ownership');
        });
    }

    private function extendTransferOrders(): void
    {
        Schema::table('transfer_orders', function (Blueprint $table): void {
            $table->string('purpose', 40)->default('inter_warehouse');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('delivery_note_id')->nullable()->index();
            $table->boolean('is_inter_company')->default(false);
            $table->uuid('inter_company_invoice_id')->nullable()->index();
        });

        Schema::table('transfer_order_lines', function (Blueprint $table): void {
            $table->decimal('quantity_damaged', 14, 4)->default(0);
            $table->decimal('discrepancy_qty', 14, 4)->default(0);
            $table->string('discrepancy_reason', 255)->nullable();
        });

        Schema::table('transfer_order_line_lots', function (Blueprint $table): void {
            $table->decimal('quantity_resolved', 14, 4)->default(0);
        });
    }

    private function createTransferDiscrepancies(): void
    {
        Schema::create('transfer_discrepancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('transfer_order_id')->index();
            $table->uuid('transfer_order_line_id')->index();
            $table->uuid('stock_item_id');
            $table->decimal('discrepancy_qty', 14, 4);
            $table->decimal('shortage_qty', 14, 4)->default(0);
            $table->decimal('damage_qty', 14, 4)->default(0);
            $table->string('discrepancy_reason', 255)->nullable();
            // pending_approval, approved_write_off, carrier_claim, returned_to_origin
            $table->string('status', 30)->default('pending_approval')->index();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $this->audit($table);
        });
    }

    private function createConsignmentTables(): void
    {
        Schema::create('consignment_consumption_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('warehouse_id')->index();
            $table->uuid('partner_id')->nullable();
            $table->uuid('agreement_id')->nullable()->index();
            $table->string('direction', 20); // customer (we invoice), vendor (we purchase)
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->string('generated_document_type', 40)->nullable(); // sales_invoice, purchase_order
            $table->uuid('generated_document_id')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('ownership_from', 30)->nullable();
            $table->string('ownership_to', 30)->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);
        });

        Schema::create('consignment_consumption_declaration_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('declaration_id')->index();
            $table->uuid('product_id');
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $this->audit($table);
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
            $this->audit($table);

            $table->unique(['tenant_id', 'agreement_number'], 'uq_consignment_agreement_number');
            $table->index(['tenant_id', 'direction', 'status'], 'idx_consignment_agreement_lookup');
        });
    }

    private function audit(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
