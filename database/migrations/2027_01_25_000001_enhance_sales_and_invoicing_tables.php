<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enhance quotes
        if (Schema::hasTable('quotes')) {
            Schema::table('quotes', function (Blueprint $table): void {
                if (! Schema::hasColumn('quotes', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->default(0)->after('currency');
                }
                if (! Schema::hasColumn('quotes', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('quotes', 'discount_policy_id')) {
                    $table->uuid('discount_policy_id')->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('quotes', 'price_list_id')) {
                    $table->uuid('price_list_id')->nullable()->after('discount_policy_id');
                }
                if (! Schema::hasColumn('quotes', 'payment_terms')) {
                    $table->string('payment_terms', 50)->nullable()->after('price_list_id');
                }
                if (! Schema::hasColumn('quotes', 'shipping_terms')) {
                    $table->string('shipping_terms', 50)->nullable()->after('payment_terms');
                }
                if (! Schema::hasColumn('quotes', 'revision_number')) {
                    $table->unsignedInteger('revision_number')->default(1)->after('shipping_terms');
                }
                if (! Schema::hasColumn('quotes', 'workflow_instance_id')) {
                    $table->uuid('workflow_instance_id')->nullable()->after('revision_number');
                }
                if (! Schema::hasColumn('quotes', 'created_by')) {
                    $table->uuid('created_by')->nullable()->after('workflow_instance_id');
                }
                if (! Schema::hasColumn('quotes', 'approved_by')) {
                    $table->uuid('approved_by')->nullable()->after('created_by');
                }
                if (! Schema::hasColumn('quotes', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (! Schema::hasColumn('quotes', 'converted_to_order_id')) {
                    $table->uuid('converted_to_order_id')->nullable()->after('approved_at');
                }
            });
        }

        // 2. Enhance quote_items
        if (Schema::hasTable('quote_items')) {
            Schema::table('quote_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('quote_items', 'tenant_id')) {
                    $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('quote_items', 'tax_code_id')) {
                    $table->uuid('tax_code_id')->nullable()->after('tax_rate');
                }
                if (! Schema::hasColumn('quote_items', 'cost_price')) {
                    $table->decimal('cost_price', 15, 4)->default(0)->after('tax_code_id');
                }
                if (! Schema::hasColumn('quote_items', 'margin_percentage')) {
                    $table->decimal('margin_percentage', 5, 2)->default(0)->after('cost_price');
                }
                if (! Schema::hasColumn('quote_items', 'uom')) {
                    $table->string('uom', 20)->default('EA')->after('margin_percentage');
                }
                if (! Schema::hasColumn('quote_items', 'notes')) {
                    $table->text('notes')->nullable()->after('uom');
                }
            });
        }

        // 3. Enhance sales_orders
        if (Schema::hasTable('sales_orders')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales_orders', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->default(0)->after('currency');
                }
                if (! Schema::hasColumn('sales_orders', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('sales_orders', 'discount_policy_id')) {
                    $table->uuid('discount_policy_id')->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('sales_orders', 'price_list_id')) {
                    $table->uuid('price_list_id')->nullable()->after('discount_policy_id');
                }
                if (! Schema::hasColumn('sales_orders', 'customer_agreement_id')) {
                    $table->uuid('customer_agreement_id')->nullable()->after('price_list_id');
                }
                if (! Schema::hasColumn('sales_orders', 'sales_rep_id')) {
                    $table->uuid('sales_rep_id')->nullable()->after('customer_agreement_id');
                }
                if (! Schema::hasColumn('sales_orders', 'workflow_instance_id')) {
                    $table->uuid('workflow_instance_id')->nullable()->after('sales_rep_id');
                }
                if (! Schema::hasColumn('sales_orders', 'credit_status')) {
                    $table->string('credit_status', 30)->default('pending_check')->index()->after('status');
                }
                if (! Schema::hasColumn('sales_orders', 'credit_hold_reason')) {
                    $table->text('credit_hold_reason')->nullable()->after('credit_status');
                }
                if (! Schema::hasColumn('sales_orders', 'inventory_status')) {
                    $table->string('inventory_status', 30)->default('pending')->index()->after('credit_hold_reason');
                }
                if (! Schema::hasColumn('sales_orders', 'fulfillment_status')) {
                    $table->string('fulfillment_status', 30)->default('unfulfilled')->index()->after('inventory_status');
                }
                if (! Schema::hasColumn('sales_orders', 'billing_status')) {
                    $table->string('billing_status', 30)->default('unbilled')->index()->after('fulfillment_status');
                }
                if (! Schema::hasColumn('sales_orders', 'payment_terms')) {
                    $table->string('payment_terms', 50)->nullable()->after('billing_status');
                }
                if (! Schema::hasColumn('sales_orders', 'shipping_method')) {
                    $table->string('shipping_method', 50)->nullable()->after('payment_terms');
                }
                if (! Schema::hasColumn('sales_orders', 'shipping_address')) {
                    $table->text('shipping_address')->nullable()->after('shipping_method');
                }
                if (! Schema::hasColumn('sales_orders', 'billing_address')) {
                    $table->text('billing_address')->nullable()->after('shipping_address');
                }
                if (! Schema::hasColumn('sales_orders', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable()->after('billing_address');
                }
                if (! Schema::hasColumn('sales_orders', 'created_by')) {
                    $table->uuid('created_by')->nullable()->after('confirmed_at');
                }
                if (! Schema::hasColumn('sales_orders', 'approved_by')) {
                    $table->uuid('approved_by')->nullable()->after('created_by');
                }
                if (! Schema::hasColumn('sales_orders', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
            });
        }

        // 4. Enhance sales_order_items
        if (Schema::hasTable('sales_order_items')) {
            Schema::table('sales_order_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales_order_items', 'tenant_id')) {
                    $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('sales_order_items', 'quantity_reserved')) {
                    $table->decimal('quantity_reserved', 12, 4)->default(0)->after('quantity');
                }
                if (! Schema::hasColumn('sales_order_items', 'quantity_invoiced')) {
                    $table->decimal('quantity_invoiced', 12, 4)->default(0)->after('quantity_delivered');
                }
                if (! Schema::hasColumn('sales_order_items', 'tax_code_id')) {
                    $table->uuid('tax_code_id')->nullable()->after('tax_rate');
                }
                if (! Schema::hasColumn('sales_order_items', 'cost_price')) {
                    $table->decimal('cost_price', 15, 4)->default(0)->after('tax_code_id');
                }
                if (! Schema::hasColumn('sales_order_items', 'uom')) {
                    $table->string('uom', 20)->default('EA')->after('cost_price');
                }
                if (! Schema::hasColumn('sales_order_items', 'notes')) {
                    $table->text('notes')->nullable()->after('uom');
                }
            });
        }

        // 5. Enhance delivery_notes
        if (Schema::hasTable('delivery_notes')) {
            Schema::table('delivery_notes', function (Blueprint $table): void {
                if (! Schema::hasColumn('delivery_notes', 'warehouse_id')) {
                    $table->uuid('warehouse_id')->nullable()->after('contact_id');
                }
                if (! Schema::hasColumn('delivery_notes', 'shipping_status')) {
                    $table->string('shipping_status', 30)->default('draft')->index()->after('status');
                }
                if (! Schema::hasColumn('delivery_notes', 'shipped_at')) {
                    $table->timestamp('shipped_at')->nullable()->after('shipping_status');
                }
                if (! Schema::hasColumn('delivery_notes', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('shipped_at');
                }
                if (! Schema::hasColumn('delivery_notes', 'tracking_number')) {
                    $table->string('tracking_number', 100)->nullable()->after('delivered_at');
                }
                if (! Schema::hasColumn('delivery_notes', 'created_by')) {
                    $table->uuid('created_by')->nullable()->after('tracking_number');
                }
            });
        }

        // 6. Enhance delivery_note_items
        if (Schema::hasTable('delivery_note_items')) {
            Schema::table('delivery_note_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('delivery_note_items', 'tenant_id')) {
                    $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('delivery_note_items', 'batch_number')) {
                    $table->string('batch_number', 100)->nullable()->after('tax_rate');
                }
                if (! Schema::hasColumn('delivery_note_items', 'serial_number')) {
                    $table->string('serial_number', 100)->nullable()->after('batch_number');
                }
                if (! Schema::hasColumn('delivery_note_items', 'warehouse_location_id')) {
                    $table->uuid('warehouse_location_id')->nullable()->after('serial_number');
                }
            });
        }

        // 7. Enhance invoices
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoices', 'status')) {
                    $table->string('status', 30)->default('draft')->index()->after('invoice_type');
                }
                if (! Schema::hasColumn('invoices', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->default(0)->after('currency');
                }
                if (! Schema::hasColumn('invoices', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('invoices', 'workflow_instance_id')) {
                    $table->uuid('workflow_instance_id')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('invoices', 'gl_journal_entry_id')) {
                    $table->uuid('gl_journal_entry_id')->nullable()->index()->after('workflow_instance_id');
                }
                if (! Schema::hasColumn('invoices', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('gl_journal_entry_id');
                }
                if (! Schema::hasColumn('invoices', 'posted_by')) {
                    $table->uuid('posted_by')->nullable()->after('posted_at');
                }
                if (! Schema::hasColumn('invoices', 'receivable_amount')) {
                    $table->decimal('receivable_amount', 15, 2)->default(0)->after('total_gross');
                }
                if (! Schema::hasColumn('invoices', 'customer_agreement_id')) {
                    $table->uuid('customer_agreement_id')->nullable()->after('receivable_amount');
                }
                if (! Schema::hasColumn('invoices', 'project_id')) {
                    $table->uuid('project_id')->nullable()->index()->after('customer_agreement_id');
                }
                if (! Schema::hasColumn('invoices', 'project_milestone_id')) {
                    $table->uuid('project_milestone_id')->nullable()->index()->after('project_id');
                }
                if (! Schema::hasColumn('invoices', 'sales_rep_id')) {
                    $table->uuid('sales_rep_id')->nullable()->after('project_milestone_id');
                }
                if (! Schema::hasColumn('invoices', 'created_by')) {
                    $table->uuid('created_by')->nullable()->after('sales_rep_id');
                }
            });
        }

        // 8. Enhance invoice_items
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoice_items', 'tenant_id')) {
                    $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('invoice_items', 'sales_order_item_id')) {
                    $table->uuid('sales_order_item_id')->nullable()->after('line_total');
                }
                if (! Schema::hasColumn('invoice_items', 'delivery_note_item_id')) {
                    $table->uuid('delivery_note_item_id')->nullable()->after('sales_order_item_id');
                }
                if (! Schema::hasColumn('invoice_items', 'revenue_account_id')) {
                    $table->uuid('revenue_account_id')->nullable()->after('delivery_note_item_id');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_account_id')) {
                    $table->uuid('tax_account_id')->nullable()->after('revenue_account_id');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_code_id')) {
                    $table->uuid('tax_code_id')->nullable()->after('tax_account_id');
                }
                if (! Schema::hasColumn('invoice_items', 'uom')) {
                    $table->string('uom', 20)->default('EA')->after('tax_code_id');
                }
                if (! Schema::hasColumn('invoice_items', 'notes')) {
                    $table->text('notes')->nullable()->after('uom');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive cleanup if needed
    }
};
