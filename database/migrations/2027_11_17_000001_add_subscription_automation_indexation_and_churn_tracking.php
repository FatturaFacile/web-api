<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sub_subscriptions', 'billing_timing')) {
            Schema::table('sub_subscriptions', function (Blueprint $table): void {
                $table->string('billing_timing', 10)->default('arrears'); // advance (anticipata), arrears (posticipata)
                $table->string('billing_alignment', 12)->default('anniversary'); // anniversary, calendar (prorated first period)
                $table->date('last_billed_period_end')->nullable();
                $table->string('indexation_type', 20)->nullable(); // istat_foi, lik
                $table->decimal('indexation_ratio_pct', 5, 2)->default(100.00); // share of the index variation applied (e.g. 75% ISTAT)
                $table->decimal('indexation_cap_pct', 6, 3)->nullable(); // max applicable increase
                $table->date('indexation_base_month')->nullable();
                $table->date('next_indexation_date')->nullable()->index();
                $table->timestamp('last_indexed_at')->nullable();
                $table->string('cancellation_category', 40)->nullable()->index(); // price, competitor, missing_features, support, no_longer_needed, payment_failure, other
                $table->text('cancellation_feedback')->nullable();
            });
        }

        if (! Schema::hasColumn('sub_invoice_schedules', 'proration_factor')) {
            Schema::table('sub_invoice_schedules', function (Blueprint $table): void {
                $table->decimal('proration_factor', 8, 6)->default(1.000000);
                $table->unsignedSmallInteger('service_days')->nullable();
                $table->unsignedSmallInteger('cycle_days')->nullable();
                $table->string('invoice_type', 20)->default('regular'); // regular, initial_prorata, final_prorata, indexation_adjustment
                $table->jsonb('calculation')->nullable();
            });
        }

        if (! Schema::hasTable('sub_inflation_indices')) {
            // Public statistical series (ISTAT FOI / BFS LIK): shared across tenants.
            Schema::create('sub_inflation_indices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('index_type', 20); // istat_foi, lik
                $table->date('reference_month'); // first day of the reference month
                $table->decimal('index_value', 14, 4);
                $table->string('base_period', 30)->nullable(); // e.g. "2010=100", "Dec 2020=100"
                $table->string('source', 120)->nullable();
                $table->timestamps();

                $table->unique(['index_type', 'reference_month']);
            });
        }

        if (! Schema::hasTable('sub_indexation_adjustments')) {
            Schema::create('sub_indexation_adjustments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('subscription_id')->constrained('sub_subscriptions')->cascadeOnDelete();
                $table->string('index_type', 20);
                $table->date('base_month');
                $table->date('reference_month');
                $table->decimal('base_index', 14, 4);
                $table->decimal('new_index', 14, 4);
                $table->decimal('variation_pct', 9, 4);
                $table->decimal('applied_pct', 9, 4);
                $table->decimal('old_recurring_amount', 15, 2);
                $table->decimal('new_recurring_amount', 15, 2);
                $table->decimal('old_mrr', 15, 2);
                $table->decimal('new_mrr', 15, 2);
                $table->date('effective_date');
                $table->string('status', 20)->default('applied')->index(); // preview, applied
                $table->timestamp('notified_at')->nullable();
                $table->jsonb('calculation_sheet')->nullable(); // "prospetto di ricalcolo"
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['subscription_id', 'effective_date', 'index_type'], 'sub_indexation_unique_cycle');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_indexation_adjustments');
        Schema::dropIfExists('sub_inflation_indices');
    }
};
