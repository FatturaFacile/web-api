<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI cash flow and demand forecasting (Mod 094): customer payment behaviour models, expected collection dates of the open
 * receivables, liquidity projections with their P10/P50/P90 daily buckets, and SKU demand forecasts with the reorder
 * thresholds they recommend. JSON columns are jsonb on PostgreSQL and text on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_behavior_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('customer_id');
            $table->unsignedInteger('sample_size')->default(0);
            $table->decimal('average_days_late', 10, 2)->default(0);
            $table->decimal('std_dev_days_late', 10, 2)->default(0);
            $table->decimal('on_time_payment_probability', 5, 4)->default(1);
            $table->integer('predicted_collection_date_offset_days')->default(0);
            $table->string('payment_risk_tier', 12)->default('low');
            $table->timestamp('last_trained_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'customer_id'], 'cpbm_tenant_customer_unique');
        });

        Schema::create('invoice_expected_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->uuid('customer_id');
            $table->date('due_date');
            $table->date('expected_collection_date');
            $table->date('earliest_collection_date');
            $table->date('latest_collection_date');
            $table->decimal('open_amount', 18, 2)->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'invoice_id'], 'iec_tenant_invoice_unique');
            $table->index(['tenant_id', 'customer_id'], 'iec_tenant_customer_index');
            $table->index(['tenant_id', 'expected_collection_date'], 'iec_tenant_expected_index');
        });

        Schema::create('ai_liquidity_forecast_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('generated_at');
            $table->unsignedSmallInteger('horizon_days');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('starting_cash_balance', 18, 2)->default(0);
            $table->jsonb('daily_projections_json');
            $table->jsonb('key_drivers_json');
            $table->jsonb('what_if_parameters_json')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'generated_at'], 'alfr_tenant_generated_index');
        });

        Schema::create('sku_demand_forecasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->date('forecast_period_start');
            $table->date('forecast_period_end');
            $table->decimal('predicted_demand_qty', 18, 4)->default(0);
            $table->decimal('confidence_lower_bound', 18, 4)->default(0);
            $table->decimal('confidence_upper_bound', 18, 4)->default(0);
            $table->decimal('recommended_reorder_point', 18, 4)->default(0);
            $table->decimal('recommended_safety_stock', 18, 4)->default(0);
            $table->decimal('seasonality_index', 8, 4)->default(1);
            $table->decimal('supplier_lead_time_avg_days', 8, 2)->default(0);
            $table->decimal('supplier_lead_time_std_dev_days', 8, 2)->default(0);
            $table->string('model', 20)->default('mean');
            $table->timestamp('applied_to_wms_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'product_id', 'warehouse_id'], 'sdf_tenant_product_warehouse_index');
            $table->index(['tenant_id', 'forecast_period_start'], 'sdf_tenant_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_demand_forecasts');
        Schema::dropIfExists('ai_liquidity_forecast_runs');
        Schema::dropIfExists('invoice_expected_collections');
        Schema::dropIfExists('customer_payment_behavior_models');
    }
};
