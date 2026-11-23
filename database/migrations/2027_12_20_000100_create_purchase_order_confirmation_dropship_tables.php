<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 046: supplier order confirmations with variances, dual unit of measure, cascading discounts, dispatch and
 * reminder logs, and the dropshipment (triangulation) flow with virtual stock and billing authorisation.
 *
 * Plain dialect-agnostic schema: runs unchanged on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->uuid('sales_order_id')->nullable()->index();
            $table->boolean('is_dropship')->default(false);
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('dispatch_channel', 20)->nullable();
            $table->string('supplier_confirmation_number', 80)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->date('promised_delivery_date')->nullable()->index();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 20)->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->string('base_uom', 20)->nullable();
            $table->decimal('uom_conversion_factor', 18, 6)->default(1);
            $table->decimal('quantity_base_ordered', 16, 4)->default(0);
            $table->decimal('quantity_base_received', 16, 4)->default(0);
            $table->decimal('quantity_confirmed', 16, 4)->nullable();
            $table->json('discount_cascade')->nullable();
            $table->decimal('discount_percent_effective', 8, 4)->default(0);
            $table->date('confirmed_delivery_date')->nullable();
            $table->date('promised_delivery_date')->nullable();
            $table->uuid('sales_order_line_id')->nullable()->index();
            $table->uuid('source_line_id')->nullable();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'billing_authorized_at')) {
                $table->timestamp('billing_authorized_at')->nullable();
                $table->string('billing_authorization_reference', 80)->nullable();
            }
        });

        Schema::create('purchase_order_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('confirmation_number', 80);
            $table->date('confirmed_at');
            $table->uuid('confirmed_by')->nullable();
            $table->boolean('has_variance')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->decimal('price_variance_total', 15, 2)->default(0);
            $table->decimal('quantity_variance_total', 16, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_confirmation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_confirmation_id')->constrained('purchase_order_confirmations')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->decimal('ordered_quantity', 16, 4);
            $table->decimal('confirmed_quantity', 16, 4);
            $table->decimal('ordered_unit_price', 15, 4);
            $table->decimal('confirmed_unit_price', 15, 4);
            $table->date('requested_delivery_date')->nullable();
            $table->date('confirmed_delivery_date')->nullable();
            $table->decimal('quantity_variance', 16, 4)->default(0);
            $table->decimal('quantity_variance_base', 16, 4)->default(0);
            $table->decimal('price_variance', 15, 4)->default(0);
            $table->decimal('price_variance_percent', 9, 4)->default(0);
            $table->decimal('line_total_variance', 15, 2)->default(0);
            $table->integer('delivery_delay_days')->default(0);
            $table->string('variance_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_order_line_id'], 'po_conf_lines_tenant_line_idx');
        });

        Schema::create('purchase_order_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('channel', 20); // pdf, email, edi, peppol
            $table->string('status', 20)->default('sent'); // sent, failed
            $table->string('recipient', 190)->nullable();
            $table->string('message_reference', 120)->nullable();
            $table->timestamp('dispatched_at');
            $table->uuid('dispatched_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->string('channel', 20)->default('email');
            $table->string('trigger', 10)->default('auto'); // auto, manual
            $table->unsignedInteger('days_overdue');
            $table->json('overdue_line_ids')->nullable();
            $table->string('message_reference', 120)->nullable();
            $table->timestamp('sent_at');
            $table->uuid('sent_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_dropships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->string('status', 20)->default('pending'); // pending, ddt_issued, partially_delivered, delivered
            $table->string('recipient_name', 190);
            $table->string('recipient_street', 190);
            $table->string('recipient_house_number', 20)->nullable();
            $table->string('recipient_postal_code', 20);
            $table->string('recipient_city', 120);
            $table->string('recipient_region', 60)->nullable();
            $table->string('recipient_country_code', 2);
            $table->string('recipient_phone', 40)->nullable();
            $table->string('recipient_email', 190)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('ddt_number', 50)->nullable();
            $table->timestamp('ddt_issued_at')->nullable();
            $table->json('ddt_payload')->nullable();
            $table->string('supplier_shipment_reference', 120)->nullable();
            $table->string('delivery_proof_reference', 190)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('billing_authorized')->default(false);
            $table->timestamp('billing_authorized_at')->nullable();
            $table->string('billing_authorization_reference', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_virtual_stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_dropship_id')->constrained('purchase_order_dropships')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->string('direction', 3); // in, out
            $table->decimal('quantity_base', 16, 4);
            $table->string('base_uom', 20)->nullable();
            $table->decimal('unit_cost_base', 15, 6)->default(0);
            $table->decimal('valued_amount', 15, 2)->default(0);
            $table->timestamp('moved_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purchase_order_line_id'], 'po_virtual_moves_tenant_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_virtual_stock_movements');
        Schema::dropIfExists('purchase_order_dropships');
        Schema::dropIfExists('purchase_order_reminders');
        Schema::dropIfExists('purchase_order_dispatches');
        Schema::dropIfExists('purchase_order_confirmation_lines');
        Schema::dropIfExists('purchase_order_confirmations');

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['base_uom', 'uom_conversion_factor', 'quantity_base_ordered', 'quantity_base_received', 'quantity_confirmed', 'discount_cascade', 'discount_percent_effective', 'confirmed_delivery_date', 'promised_delivery_date', 'sales_order_line_id', 'source_line_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['sales_order_id', 'is_dropship', 'source_type', 'source_id', 'dispatch_channel', 'supplier_confirmation_number', 'confirmed_at', 'promised_delivery_date', 'exchange_rate_date', 'exchange_rate_source', 'reminder_count', 'last_reminder_at']);
        });
    }
};
