<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebates and trade promotions: volume rebate agreements with customers (sales) or suppliers (purchases), their
     * tiers, the accruals calculated from invoiced sales or purchases, the settlements and the promotions with date
     * ranges. New tables only; the tier arithmetic and the settlement rules live in the application.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rebate_agreements')) {
            Schema::create('rebate_agreements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('direction', 10)->default('sales');
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('tier_mode', 12)->default('retroactive');
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 12)->default('draft');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'contact_id']);
                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('rebate_tiers')) {
            Schema::create('rebate_tiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('agreement_id')->constrained('rebate_agreements')->cascadeOnDelete();
                $table->decimal('threshold_amount', 15, 2);
                $table->decimal('rate_percent', 7, 4);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['agreement_id', 'threshold_amount']);
            });
        }

        if (! Schema::hasTable('rebate_settlements')) {
            Schema::create('rebate_settlements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('agreement_id')->constrained('rebate_agreements')->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('settlement_type', 20);
                $table->uuid('credit_note_id')->nullable()->index();
                $table->date('settled_on');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'agreement_id']);
            });
        }

        if (! Schema::hasTable('rebate_accruals')) {
            Schema::create('rebate_accruals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('agreement_id')->constrained('rebate_agreements')->cascadeOnDelete();
                $table->date('as_of');
                $table->decimal('base_amount', 15, 2);
                $table->decimal('cumulative_rebate', 15, 2);
                $table->decimal('rebate_amount', 15, 2);
                $table->decimal('applied_rate_percent', 7, 4)->nullable();
                $table->string('status', 12)->default('accrued');
                $table->foreignUuid('settlement_id')->nullable()->constrained('rebate_settlements')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'agreement_id', 'status']);
            });
        }

        if (! Schema::hasTable('rebate_promotions')) {
            Schema::create('rebate_promotions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('discount_type', 16)->default('percentage');
                $table->decimal('discount_value', 15, 4);
                $table->uuid('product_id')->nullable()->index();
                $table->uuid('contact_id')->nullable()->index();
                $table->decimal('min_quantity', 12, 4)->nullable();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'starts_on', 'ends_on']);
                $table->index(['tenant_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rebate_accruals');
        Schema::dropIfExists('rebate_settlements');
        Schema::dropIfExists('rebate_tiers');
        Schema::dropIfExists('rebate_promotions');
        Schema::dropIfExists('rebate_agreements');
    }
};
