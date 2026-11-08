<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise sales cycle (additive only):
 *
 * - discount policies become promotions: a priority, whether they stack with others, whether they apply by themselves
 *   (without being named on the document) and an optional customer; a rule can target one customer, and the rules get
 *   the tenant column their model already requires (backfilled from their policy);
 * - delivery notes record the shipping terms (porto) and who carries the goods (sender, recipient or carrier), as free
 *   descriptive data printed on the DDT;
 * - a quote can be revised: the revision links to the quote it replaces, which is marked superseded;
 * - sales returns (RMA) with their lines: what the customer sends back, the stock re-entry and the credit note.
 *
 * Every step checks the schema first, so the migration can run again on a database that already has part of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_policies', function (Blueprint $table): void {
            if (! Schema::hasColumn('discount_policies', 'priority')) {
                $table->unsignedInteger('priority')->default(100);
            }

            if (! Schema::hasColumn('discount_policies', 'is_stackable')) {
                $table->boolean('is_stackable')->default(true);
            }

            if (! Schema::hasColumn('discount_policies', 'auto_apply')) {
                $table->boolean('auto_apply')->default(false);
            }

            if (! Schema::hasColumn('discount_policies', 'contact_id')) {
                $table->uuid('contact_id')->nullable()->index();
            }
        });

        Schema::table('discount_policy_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('discount_policy_rules', 'contact_id')) {
                $table->uuid('contact_id')->nullable()->index();
            }

            // The rule model is tenant scoped like every enterprise model, but the table had no tenant column: every
            // read of the rules failed. The column is backfilled from the policy of the rule.
            if (! Schema::hasColumn('discount_policy_rules', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->index();
            }

            if (! Schema::hasColumn('discount_policy_rules', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('discount_policy_rules')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => DB::raw('(select discount_policies.tenant_id from discount_policies where discount_policies.id = discount_policy_rules.discount_policy_id)')]);

        // Lines written by the sales API of the app had no tenant: the tenant-scoped models of the invoicing module saw
        // them as generic rows and refused to update them (delivered and invoiced quantities). Each line takes the tenant
        // of its document.
        foreach (['quote_items' => ['quotes', 'quote_id'], 'sales_order_items' => ['sales_orders', 'sales_order_id'], 'delivery_note_items' => ['delivery_notes', 'delivery_note_id']] as $lines => [$documents, $foreignKey]) {
            if (Schema::hasColumn($lines, 'tenant_id')) {
                DB::table($lines)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => DB::raw("(select {$documents}.tenant_id from {$documents} where {$documents}.id = {$lines}.{$foreignKey})")]);
            }
        }

        Schema::table('delivery_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_notes', 'shipping_terms')) {
                $table->string('shipping_terms', 100)->nullable();
            }

            if (! Schema::hasColumn('delivery_notes', 'transport_by')) {
                $table->string('transport_by', 20)->nullable();
            }
        });

        Schema::table('quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotes', 'revision_of_quote_id')) {
                $table->uuid('revision_of_quote_id')->nullable()->index();
            }

            if (! Schema::hasColumn('quotes', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable();
            }
        });

        if (! Schema::hasTable('sales_returns')) {
            Schema::create('sales_returns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
                $table->string('return_number', 50);
                $table->date('date');
                $table->string('status', 20)->default('requested');
                $table->uuid('sales_order_id')->nullable()->index();
                $table->uuid('delivery_note_id')->nullable()->index();
                $table->uuid('invoice_id')->nullable()->index();
                $table->uuid('warehouse_id')->nullable();
                $table->string('reason', 500);
                $table->text('notes')->nullable();
                $table->uuid('credit_note_id')->nullable()->index();
                $table->uuid('created_by')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('credited_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'return_number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'contact_id']);
            });
        }

        if (! Schema::hasTable('sales_return_items')) {
            Schema::create('sales_return_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
                $table->uuid('delivery_note_item_id')->nullable()->index();
                $table->uuid('invoice_item_id')->nullable()->index();
                $table->uuid('sales_order_item_id')->nullable()->index();
                $table->uuid('product_id')->nullable()->index();
                $table->string('description', 1000);
                $table->decimal('quantity', 15, 4);
                $table->decimal('quantity_received', 15, 4)->default(0);
                $table->decimal('unit_price', 15, 4)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('discount_percentage', 5, 2)->default(0);
                $table->string('condition', 20)->nullable();
                $table->boolean('restock')->default(true);
                $table->timestamps();

                $table->index(['tenant_id', 'sales_return_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');

        Schema::table('quotes', function (Blueprint $table): void {
            foreach (['revision_of_quote_id', 'superseded_at'] as $column) {
                if (Schema::hasColumn('quotes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('delivery_notes', function (Blueprint $table): void {
            foreach (['shipping_terms', 'transport_by'] as $column) {
                if (Schema::hasColumn('delivery_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('discount_policy_rules', function (Blueprint $table): void {
            foreach (['contact_id', 'tenant_id', 'deleted_at'] as $column) {
                if (Schema::hasColumn('discount_policy_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('discount_policies', function (Blueprint $table): void {
            foreach (['priority', 'is_stackable', 'auto_apply', 'contact_id'] as $column) {
                if (Schema::hasColumn('discount_policies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
