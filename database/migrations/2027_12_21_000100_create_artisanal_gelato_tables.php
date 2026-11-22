<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artisanal gelato, chocolaterie and cold pastry vertical (Mod 398): technical ingredients, balanced recipes and their lines,
 * pasteurization and churning lots with HACCP readings and raw material genealogy, ganache specs, the showcase wells with the
 * published allergen book snapshots, the praline box / cake / tub orders and the scale readings.
 *
 * Tenant isolation is the application-level TenantScope used by every module (nullable tenant_id, global rows are read only);
 * the schema is dialect agnostic (jsonb on PostgreSQL, text on SQLite) and carries no engine specific policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gelato_technical_ingredients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 48);
            $table->jsonb('name');
            $table->string('category', 24);
            $table->decimal('pod', 8, 2)->default(0);
            $table->decimal('pac', 8, 2)->default(0);
            $table->decimal('milk_fat_percent', 6, 2)->default(0);
            $table->decimal('other_fat_percent', 6, 2)->default(0);
            $table->decimal('slng_percent', 6, 2)->default(0);
            $table->decimal('total_solids_percent', 6, 2)->default(0);
            $table->decimal('water_percent', 6, 2)->default(0);
            $table->decimal('sugar_percent', 6, 2)->default(0);
            $table->string('solute_class', 12)->default('none');
            $table->decimal('fiber_percent', 6, 2)->default(0);
            $table->decimal('protein_percent', 6, 2)->default(0);
            $table->decimal('kcal_per_100g', 7, 1)->default(0);
            $table->decimal('cost_per_kg', 10, 4)->default(0);
            $table->decimal('brix', 5, 1)->nullable();
            $table->decimal('cocoa_butter_percent', 6, 2)->nullable();
            $table->decimal('cocoa_mass_percent', 6, 2)->nullable();
            $table->jsonb('allergens');
            $table->boolean('is_vegan')->default(true);
            $table->boolean('is_defrosted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category']);
        });

        Schema::create('gelato_balanced_recipes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 48);
            $table->jsonb('name');
            $table->string('category', 24);
            $table->string('kind', 16);
            $table->decimal('batch_weight_grams', 12, 2)->default(1000);
            $table->decimal('serving_portion_grams', 8, 2)->default(80);
            $table->decimal('tub_weight_grams', 8, 2)->default(2500);
            $table->boolean('is_defrosted')->default(false);
            $table->decimal('calculated_pod_percent', 8, 2)->default(0);
            $table->decimal('calculated_pac_index', 8, 2)->default(0);
            $table->decimal('milk_fat_percent', 6, 2)->default(0);
            $table->decimal('other_fat_percent', 6, 2)->default(0);
            $table->decimal('slng_percent', 6, 2)->default(0);
            $table->decimal('slng_max_safe_threshold', 6, 2)->default(0);
            $table->decimal('total_solids_percent', 6, 2)->default(0);
            $table->decimal('ideal_serving_temp_celsius', 5, 1)->default(0);
            $table->decimal('overrun_percent', 6, 2)->default(0);
            $table->decimal('food_cost_per_kg', 10, 4)->default(0);
            $table->jsonb('metrics')->nullable();
            $table->jsonb('allergens')->nullable();
            $table->jsonb('dietary')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'code']);
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('gelato_recipe_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('gelato_balanced_recipes')->cascadeOnDelete();
            $table->uuid('ingredient_id')->nullable();
            $table->uuid('sub_recipe_id')->nullable();
            $table->decimal('grams', 12, 3);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'recipe_id']);
            $table->index('ingredient_id');
            $table->index('sub_recipe_id');
        });

        Schema::create('gelato_pasteurization_churn_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('lot_code', 48);
            $table->string('kind', 16);
            $table->string('method', 12)->default('direct');
            $table->uuid('recipe_id')->nullable();
            $table->uuid('parent_lot_id')->nullable();
            $table->string('status', 16)->default('in_progress');
            $table->uuid('operator_id')->nullable();
            $table->string('equipment', 64)->nullable();
            $table->decimal('mix_weight_grams', 12, 2)->default(0);
            $table->decimal('mix_volume_liters', 10, 2)->default(0);
            $table->decimal('gelato_volume_liters', 10, 2)->nullable();
            $table->decimal('overrun_percent', 6, 2)->nullable();
            $table->decimal('yield_grams', 12, 2)->nullable();
            $table->unsignedSmallInteger('yield_tubs')->nullable();
            $table->timestamp('aged_since')->nullable();
            $table->unsignedInteger('aging_minutes')->nullable();
            $table->jsonb('haccp_readings')->nullable();
            $table->boolean('haccp_passed')->nullable();
            $table->jsonb('haccp_findings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'lot_code']);
            $table->index(['tenant_id', 'kind', 'status']);
            $table->index('parent_lot_id');
        });

        Schema::create('gelato_lot_material_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lot_id')->constrained('gelato_pasteurization_churn_lots')->cascadeOnDelete();
            $table->uuid('ingredient_id')->nullable();
            $table->uuid('source_lot_id')->nullable();
            $table->string('raw_lot_code', 64)->nullable();
            $table->decimal('grams', 12, 3);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'lot_id']);
            $table->index('source_lot_id');
        });

        Schema::create('gelato_chocolate_ganache_specs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('recipe_id');
            $table->string('chocolate_type', 8)->default('dark');
            $table->decimal('cocoa_butter_percent', 6, 2)->default(0);
            $table->decimal('cocoa_mass_percent', 6, 2)->default(0);
            $table->decimal('water_percent', 6, 2)->default(0);
            $table->decimal('water_activity', 5, 3)->default(0);
            $table->unsignedSmallInteger('shelf_life_days')->default(0);
            $table->string('risk_band', 12)->default('low');
            $table->jsonb('tempering_curve')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'recipe_id']);
        });

        Schema::create('gelato_showcase_active_flavors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('location_id')->nullable();
            $table->string('location_key', 64)->default('main');
            $table->unsignedSmallInteger('slot_number');
            $table->uuid('recipe_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'location_key', 'slot_number']);
        });

        Schema::create('gelato_showcase_ingredient_books', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('location_key', 64)->default('main');
            $table->string('jurisdiction', 2)->default('IT');
            $table->unsignedInteger('version');
            $table->jsonb('payload');
            $table->timestamp('published_at');
            $table->uuid('published_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'location_key', 'version']);
        });

        Schema::create('gelato_assorted_box_and_cake_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('order_number', 32);
            $table->string('kind', 16);
            $table->string('customer_name', 160);
            $table->string('customer_phone', 40)->nullable();
            $table->jsonb('components');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('component_cost', 12, 2)->default(0);
            $table->string('status', 16)->default('pre_order');
            $table->timestamp('pickup_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status', 'pickup_at']);
        });

        Schema::create('gelato_scale_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('lot_id')->nullable();
            $table->uuid('ingredient_id')->nullable();
            $table->decimal('grams', 12, 3);
            $table->string('source', 12)->default('manual');
            $table->string('device', 64)->nullable();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'lot_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'gelato_scale_readings',
            'gelato_assorted_box_and_cake_orders',
            'gelato_showcase_ingredient_books',
            'gelato_showcase_active_flavors',
            'gelato_chocolate_ganache_specs',
            'gelato_lot_material_consumptions',
            'gelato_pasteurization_churn_lots',
            'gelato_recipe_lines',
            'gelato_balanced_recipes',
            'gelato_technical_ingredients',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
