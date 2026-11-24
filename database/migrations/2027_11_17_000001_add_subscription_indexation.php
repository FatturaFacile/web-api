<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sub_subscriptions', 'indexation_type')) {
            Schema::table('sub_subscriptions', function (Blueprint $table): void {
                $table->string('indexation_type', 20)->nullable(); // istat_foi (IT), lik (CH)
                $table->decimal('indexation_ratio_pct', 5, 2)->default(100.00); // share of the index variation applied (e.g. 75% ISTAT)
                $table->decimal('indexation_cap_pct', 7, 3)->nullable(); // maximum increase per adjustment
                $table->date('indexation_base_month')->nullable(); // month of the index the next variation is measured from
                $table->date('next_indexation_date')->nullable()->index();
                $table->timestamp('last_indexed_at')->nullable();
            });
        }

        if (! Schema::hasTable('sub_inflation_indices')) {
            // Statistical series (ISTAT FOI, BFS LIK) entered or imported by each company.
            Schema::create('sub_inflation_indices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('index_type', 20);
                $table->date('reference_month'); // first day of the month the index refers to
                $table->decimal('index_value', 14, 4);
                $table->string('base_period', 30)->nullable(); // e.g. "2010=100", "Dec 2020=100"
                $table->string('source', 120)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'index_type', 'reference_month']);
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
                $table->timestamp('notified_at')->nullable();
                $table->jsonb('calculation_sheet')->nullable(); // prospetto di ricalcolo
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

        if (Schema::hasColumn('sub_subscriptions', 'indexation_type')) {
            Schema::table('sub_subscriptions', function (Blueprint $table): void {
                $table->dropIndex(['next_indexation_date']);
                $table->dropColumn(['indexation_type', 'indexation_ratio_pct', 'indexation_cap_pct', 'indexation_base_month', 'next_indexation_date', 'last_indexed_at']);
            });
        }
    }
};
