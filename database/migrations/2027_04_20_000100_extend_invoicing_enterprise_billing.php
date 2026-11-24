<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise billing on the invoices table (additive only):
 *
 * - the exchange rate of a foreign-currency document is frozen at emission, with where it came from and its date;
 * - a document can carry a payment term, whose installments (scadenze) are stored at emission and feed receivables and
 *   the receivables aging;
 * - a line of a final invoice can deduct a line of a down-payment invoice (deducted_invoice_item_id);
 * - the billing plan items get the tenant and soft-delete columns their model already requires, backfilled from their plan.
 *
 * Every step checks the schema first, so the migration can run again on a database that already has part of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'exchange_rate_source')) {
                $table->string('exchange_rate_source', 30)->nullable();
            }

            if (! Schema::hasColumn('invoices', 'exchange_rate_date')) {
                $table->date('exchange_rate_date')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'payment_term_id')) {
                $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'deducted_invoice_item_id')) {
                $table->uuid('deducted_invoice_item_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('invoice_installments')) {
            Schema::create('invoice_installments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->date('due_date');
                $table->decimal('amount', 15, 2);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['invoice_id', 'sequence']);
                $table->index(['tenant_id', 'due_date']);
            });
        }

        Schema::table('billing_schedule_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_schedule_items', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->index();
            }

            if (! Schema::hasColumn('billing_schedule_items', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('billing_schedule_items')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => DB::raw('(select billing_schedules.tenant_id from billing_schedules where billing_schedules.id = billing_schedule_items.billing_schedule_id)')]);
    }

    public function down(): void
    {
        Schema::table('billing_schedule_items', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_schedule_items', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('billing_schedule_items', 'tenant_id')) {
                $table->dropColumn('tenant_id');
            }
        });

        Schema::dropIfExists('invoice_installments');

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (Schema::hasColumn('invoice_items', 'deducted_invoice_item_id')) {
                $table->dropColumn('deducted_invoice_item_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'payment_term_id')) {
                $table->dropConstrainedForeignId('payment_term_id');
            }

            foreach (['exchange_rate_source', 'exchange_rate_date'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
