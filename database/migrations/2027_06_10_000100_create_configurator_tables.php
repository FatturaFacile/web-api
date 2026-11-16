<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configure-price-quote: configurable product models with option groups, options (price delta or multiplier, cost,
     * optional linked product), the rules over the selected options, and the saved configurations that end as quotes.
     * Amounts are decimals, enumerations are plain strings checked by the application (no engine-specific constraint).
     * New tables only: nothing existing is touched.
     */
    public function up(): void
    {
        Schema::create('cfg_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->foreignUuid('base_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('base_price_from_product')->default(false);
            $table->decimal('base_price', 15, 4)->default(0);
            $table->decimal('base_cost', 15, 4)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('tax_rate', 5, 2)->default(22);
            $table->decimal('min_margin_percent', 5, 2)->nullable();
            $table->jsonb('volume_breaks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('cfg_option_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cfg_models')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->string('type', 20);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_select')->nullable();
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->decimal('range_min', 15, 4)->nullable();
            $table->decimal('range_max', 15, 4)->nullable();
            $table->decimal('range_step', 15, 4)->nullable();
            $table->decimal('default_value', 15, 4)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price_delta', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'model_id', 'sort_order']);
        });

        Schema::create('cfg_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cfg_models')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('cfg_option_groups')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->decimal('price_delta', 15, 4)->default(0);
            $table->decimal('price_multiplier', 10, 4)->default(1);
            $table->decimal('cost', 15, 4)->default(0);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('price_from_product')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'model_id', 'code']);
            $table->index(['tenant_id', 'group_id', 'sort_order']);
        });

        Schema::create('cfg_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cfg_models')->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('type', 20);
            $table->string('match', 10)->default('all');
            $table->jsonb('conditions')->nullable();
            $table->jsonb('target_options')->nullable();
            $table->string('price_effect_type', 20)->nullable();
            $table->decimal('price_effect_value', 15, 4)->nullable();
            $table->string('message', 255)->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'model_id', 'type', 'priority']);
        });

        Schema::create('cfg_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('cfg_models')->restrictOnDelete();
            $table->string('number', 30);
            $table->string('name', 190)->nullable();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->jsonb('selections')->nullable();
            $table->jsonb('pricing')->nullable();
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('margin_percent', 8, 2)->nullable();
            $table->decimal('min_margin_percent', 5, 2)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('model_version')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->uuid('approval_requested_by')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfg_configurations');
        Schema::dropIfExists('cfg_rules');
        Schema::dropIfExists('cfg_options');
        Schema::dropIfExists('cfg_option_groups');
        Schema::dropIfExists('cfg_models');
    }
};
