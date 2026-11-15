<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full point of sale on top of pos_terminals, pos_sessions and pos_sales: cash drawer movements, payments split by
     * method, promotions with the discounts applied to a sale, partial returns and exchanges, and the X and Z reports
     * of a cash session. New tables only; every rule is enforced by the application, not by the engine.
     */
    public function up(): void
    {
        Schema::create('retail_cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 190)->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pos_session_id']);
        });

        Schema::create('retail_sale_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->string('method', 30);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pos_sale_id']);
            $table->index(['tenant_id', 'method']);
        });

        Schema::create('retail_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->string('type', 20);
            $table->decimal('value', 12, 4)->default(0);
            $table->uuid('product_id')->nullable()->index();
            $table->unsignedSmallInteger('buy_quantity')->nullable();
            $table->unsignedSmallInteger('get_quantity')->nullable();
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('weekdays')->nullable();
            $table->string('time_from', 5)->nullable();
            $table->string('time_to', 5)->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_stackable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('retail_sale_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->uuid('promotion_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 190);
            $table->decimal('discount_net', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'pos_sale_id']);
        });

        Schema::create('retail_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignUuid('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->uuid('exchange_sale_id')->nullable()->index();
            $table->uuid('commercial_document_id')->nullable();
            $table->string('number', 30);
            $table->string('reason', 190)->nullable();
            $table->string('refund_method', 20);
            $table->decimal('total_net', 12, 2);
            $table->decimal('total_tax', 12, 2);
            $table->decimal('total_gross', 12, 2);
            $table->decimal('credit_applied', 12, 2)->default(0);
            $table->decimal('cash_refunded', 12, 2)->default(0);
            $table->decimal('other_refunded', 12, 2)->default(0);
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'pos_sale_id']);
            $table->index(['tenant_id', 'pos_session_id']);
        });

        Schema::create('retail_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('retail_return_id')->constrained('retail_returns')->cascadeOnDelete();
            $table->uuid('pos_sale_item_id')->index();
            $table->uuid('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('net_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'retail_return_id']);
        });

        Schema::create('retail_session_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->string('kind', 1);
            $table->string('number', 30)->nullable();
            $table->date('business_date');
            $table->json('totals');
            $table->uuid('generated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pos_session_id', 'kind']);
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['retail_session_reports', 'retail_return_lines', 'retail_returns', 'retail_sale_promotions', 'retail_promotions', 'retail_sale_payments', 'retail_cash_movements'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
