<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mod 230 — technical-commercial CPQ configurator: product models, characteristics and options, constraint rules,
     * super-BOM / routing mappings, pricing formulas and the configured instances (deduplicated by configuration hash).
     * Labels and messages are stored as JSON maps {it, en, de, fr} written by the seeders. Enumerations are plain strings
     * checked by the application; JSON columns and indexes work the same on SQLite and PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('cpq_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('base_price', 15, 4)->default(0);
            $table->decimal('base_cost', 15, 4)->default(0);
            $table->unsignedSmallInteger('base_lead_time_days')->default(0);
            $table->decimal('target_margin_percent', 5, 2)->nullable();
            $table->decimal('max_discount_percent', 5, 2)->default(100);
            $table->string('part_number_prefix', 12);
            $table->unsignedInteger('part_number_sequence')->default(0);
            $table->json('steps')->nullable();
            $table->json('visual_base')->nullable();
            $table->json('proposal_texts')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('cpq_characteristics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('step_code', 40)->nullable();
            $table->string('type', 20);
            $table->json('label');
            $table->json('help')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('range_min', 15, 4)->nullable();
            $table->decimal('range_max', 15, 4)->nullable();
            $table->decimal('range_step', 15, 4)->nullable();
            $table->string('default_value', 100)->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('part_number')->nullable();
            $table->json('visual')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'sort_order']);
        });

        Schema::create('cpq_characteristic_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->foreignUuid('characteristic_id')->constrained('cpq_characteristics')->cascadeOnDelete();
            $table->string('code', 40);
            $table->json('label');
            $table->json('description')->nullable();
            $table->string('part_number_code', 12)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('properties')->nullable();
            $table->json('visual')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'characteristic_id', 'code']);
            $table->index(['tenant_id', 'model_id']);
        });

        Schema::create('cpq_constraint_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('rule_type', 30);
            $table->text('condition_expression')->nullable();
            $table->string('target_characteristic', 40)->nullable();
            $table->json('target_options')->nullable();
            $table->json('forced_value')->nullable();
            $table->json('params')->nullable();
            $table->json('message')->nullable();
            $table->string('severity', 10)->default('error');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'priority']);
        });

        Schema::create('cpq_superbom_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('item_code', 50);
            $table->json('item_name');
            $table->string('uom', 20)->default('PCS');
            $table->text('condition_expression')->nullable();
            $table->text('quantity_formula')->default('1');
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->text('unit_cost_formula')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->json('auto_include_message')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'sort_order']);
        });

        Schema::create('cpq_routing_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->string('code', 60);
            $table->json('operation_name');
            $table->string('work_center', 50);
            $table->text('condition_expression')->nullable();
            $table->text('setup_hours_formula')->default('0');
            $table->text('run_hours_formula')->default('0');
            $table->decimal('hourly_rate', 15, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'sort_order']);
        });

        Schema::create('cpq_pricing_formulas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->string('code', 60);
            $table->json('label');
            $table->string('effect', 24);
            $table->text('condition_expression')->nullable();
            $table->text('formula');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'sort_order']);
        });

        Schema::create('cpq_configured_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cpq_models')->cascadeOnDelete();
            $table->unsignedInteger('model_version');
            $table->string('configuration_hash', 64);
            $table->string('part_number', 80);
            $table->json('configuration');
            $table->json('snapshot');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('industrial_cost', 15, 4)->default(0);
            $table->decimal('list_price', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);
            $table->decimal('margin_amount', 15, 4)->default(0);
            $table->decimal('margin_percent', 7, 2)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->string('status', 20)->default('confirmed');
            $table->uuid('mfg_item_id')->nullable();
            $table->uuid('mfg_bom_id')->nullable();
            $table->string('customer_reference', 190)->nullable();
            $table->unsignedInteger('reuse_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'configuration_hash'], 'cpq_instances_hash_unique');
            $table->unique(['tenant_id', 'part_number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['cpq_configured_instances', 'cpq_pricing_formulas', 'cpq_routing_mappings', 'cpq_superbom_mappings', 'cpq_constraint_rules', 'cpq_characteristic_options', 'cpq_characteristics', 'cpq_models'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
