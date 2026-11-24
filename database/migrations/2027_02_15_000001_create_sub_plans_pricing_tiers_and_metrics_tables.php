<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Subscription Products Catalog
        if (! Schema::hasTable('sub_products')) {
            Schema::create('sub_products', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('product_code', 50)->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('product_type', 30)->default('saas_license'); // recurring_service, saas_license, usage_meter, support_plan, hardware_lease
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'product_code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX sub_products_metadata_gin ON sub_products USING GIN (metadata)');
            }
        }

        // 2. Subscription Plans (Catalog with Interval & Pricing Model)
        if (! Schema::hasTable('sub_plans')) {
            Schema::create('sub_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('product_id')->nullable()->constrained('sub_products')->nullOnDelete();
                $table->string('plan_code', 50)->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('billing_interval', 20)->default('monthly'); // monthly, quarterly, semiannual, annual, multi_year
                $table->unsignedSmallInteger('interval_count')->default(1);
                $table->string('pricing_model', 30)->default('flat_fee'); // flat_fee, per_unit, tiered, volume, metered, hybrid
                $table->string('currency', 3)->default('EUR');
                $table->decimal('setup_fee', 15, 2)->default(0.00);
                $table->decimal('base_price', 15, 4)->default(0.0000);
                $table->unsignedInteger('trial_period_days')->default(0);
                $table->unsignedInteger('min_commitment_cycles')->default(1);
                $table->boolean('is_active')->default(true);
                $table->text('terms_conditions')->nullable();
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'plan_code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX sub_plans_translations_gin ON sub_plans USING GIN (translations)');
                DB::statement('CREATE INDEX sub_plans_metadata_gin ON sub_plans USING GIN (metadata)');
                DB::statement('CREATE INDEX sub_plans_active_idx ON sub_plans (tenant_id, billing_interval) WHERE is_active = true');
            }
        }

        // 3. Pricing Tiers (Volume & Tiered Pricing Brackets)
        if (! Schema::hasTable('sub_pricing_tiers')) {
            Schema::create('sub_pricing_tiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('plan_id')->constrained('sub_plans')->cascadeOnDelete();
                $table->unsignedInteger('tier_number')->default(1);
                $table->decimal('from_quantity', 14, 4);
                $table->decimal('to_quantity', 14, 4)->nullable(); // null represents infinite upper limit
                $table->decimal('unit_price', 15, 4)->default(0.0000);
                $table->decimal('flat_fee', 15, 2)->default(0.00);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();

                $table->index(['plan_id', 'tier_number']);
            });
        }

        // 4. Plan Features & Included Quotas
        if (! Schema::hasTable('sub_plan_features')) {
            Schema::create('sub_plan_features', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('plan_id')->constrained('sub_plans')->cascadeOnDelete();
                $table->string('feature_code', 50)->index();
                $table->string('name', 150);
                $table->string('metric_type', 30)->default('metered_quota'); // boolean, metered_quota, unlimited
                $table->decimal('included_quantity', 14, 4)->default(0.0000);
                $table->decimal('overage_unit_price', 15, 4)->default(0.0000);
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 5. Price Books (Multi-Currency & Regional Parity)
        if (! Schema::hasTable('sub_price_books')) {
            Schema::create('sub_price_books', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50)->index();
                $table->string('name', 150);
                $table->string('currency', 3)->default('EUR');
                $table->boolean('is_default')->default(false);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('rate_overrides')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 6. Usage Metrics (Meter Definitions for Consumption Tracking)
        if (! Schema::hasTable('sub_usage_metrics')) {
            Schema::create('sub_usage_metrics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('metric_code', 50)->index();
                $table->string('name', 150);
                $table->string('aggregation_type', 30)->default('sum'); // sum, max, last, count
                $table->string('unit_of_measure', 30)->default('unit'); // gb, api_call, transaction, hour, unit
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'metric_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_usage_metrics');
        Schema::dropIfExists('sub_price_books');
        Schema::dropIfExists('sub_plan_features');
        Schema::dropIfExists('sub_pricing_tiers');
        Schema::dropIfExists('sub_plans');
        Schema::dropIfExists('sub_products');
    }
};
