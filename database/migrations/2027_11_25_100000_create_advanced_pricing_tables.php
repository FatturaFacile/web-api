<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Advanced pricing: price list channel/rounding/priority, cascading discounts on list items, customer special
     * prices, an immutable price history, time-bounded promotions, margin policies and the override approvals.
     */
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->string('code', 40)->nullable();
            $table->string('channel', 20)->default('DEFAULT');
            $table->string('rounding_mode', 20)->default('NONE');
            $table->integer('priority')->default(0);

            $table->index(['tenant_id', 'channel', 'currency'], 'idx_price_lists_channel');
        });

        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->decimal('max_quantity', 12, 4)->nullable();
            $table->string('discount_cascade', 120)->nullable();
            $table->string('discount_mode', 20)->default('MULTIPLICATIVE');
        });

        Schema::create('customer_special_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('fixed_price', 15, 4)->nullable();
            $table->string('discount_cascade', 120)->nullable();
            $table->string('discount_mode', 20)->default('MULTIPLICATIVE');
            $table->string('currency', 3)->default('EUR');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id', 'product_id'], 'idx_special_prices_lookup');
        });

        Schema::create('price_list_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('price_list_id')->nullable()->index();
            $table->uuid('price_list_item_id')->nullable()->index();
            $table->uuid('customer_special_price_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->decimal('old_price', 15, 4)->nullable();
            $table->decimal('new_price', 15, 4)->nullable();
            $table->string('old_discount_cascade', 120)->nullable();
            $table->string('new_discount_cascade', 120)->nullable();
            $table->timestamp('effective_at');
            $table->uuid('changed_by_user_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'price_list_id'], 'idx_price_histories_list');
        });

        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('type', 30);
            $table->json('rules');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_stackable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('margin_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope', 20);
            $table->uuid('scope_id')->nullable();
            $table->decimal('min_margin_percent', 7, 2);
            $table->string('cost_basis', 30)->default('LAST_PURCHASE_COST');
            $table->boolean('block_on_violation')->default(true);
            $table->string('requires_approval_role', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'scope', 'scope_id'], 'idx_margin_policies_scope');
        });

        Schema::create('margin_override_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->decimal('calculated_margin_percent', 8, 2);
            $table->decimal('threshold_percent', 7, 2);
            $table->uuid('approved_by_user_id');
            $table->timestamp('approved_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'document_type', 'document_id'], 'idx_margin_approvals_document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('margin_override_approvals');
        Schema::dropIfExists('margin_policies');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_list_histories');
        Schema::dropIfExists('customer_special_prices');

        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->dropColumn(['max_quantity', 'discount_cascade', 'discount_mode']);
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropIndex('idx_price_lists_channel');
            $table->dropColumn(['code', 'channel', 'rounding_mode', 'priority']);
        });
    }
};
