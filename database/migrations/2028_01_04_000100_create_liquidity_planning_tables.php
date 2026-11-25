<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash flow forecasting and liquidity planning (Mod 017): the per-tenant planning settings (jurisdiction, share capital,
 * thresholds), the manual forecast items of the CFO, the saved what-if scenarios and the crisis early-warning alerts
 * (CO 725 / 725a, Art. 2086 c.c. and CCII). Dialect-agnostic: runs unchanged on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lp_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('jurisdiction', 2)->default('IT'); // CH (CO 725/725a), IT (Art. 2446/2447 c.c., CCII)
            $table->decimal('share_capital', 15, 2)->nullable();
            $table->decimal('legal_minimum_capital', 15, 2)->nullable();
            $table->decimal('minimum_liquidity', 15, 2)->default(0);
            $table->decimal('dscr_critical_threshold', 6, 2)->default(1.00);
            $table->decimal('dscr_warning_threshold', 6, 2)->default(1.20);
            $table->decimal('max_pfn_ebitda', 6, 2)->default(3.50);
            $table->decimal('ebitda_override', 15, 2)->nullable();
            $table->decimal('net_equity_override', 15, 2)->nullable();
            $table->unsignedSmallInteger('alert_cooldown_hours')->default(24);
            $table->json('notify_emails')->nullable();
            $table->boolean('email_alerts_enabled')->default(true);
            $table->unsignedSmallInteger('collection_behavior_days')->default(0);
            $table->decimal('payroll_monthly_override', 15, 2)->nullable();
            $table->unsignedTinyInteger('payroll_pay_day')->default(27);
            $table->decimal('tax_monthly_estimate', 15, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->unique(['tenant_id']);
        });

        Schema::create('lp_manual_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('label', 150);
            $table->string('tier', 12); // certain, probable, estimated
            $table->string('category', 24);
            $table->string('direction', 8); // inflow, outflow
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->date('first_date');
            $table->string('frequency', 10)->default('once'); // once, weekly, monthly, quarterly, yearly
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'is_active', 'first_date']);
        });

        Schema::create('lp_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('levers');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('lp_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('severity', 10);
            $table->string('status', 14)->default('open'); // open, acknowledged, resolved
            $table->string('fingerprint', 64);
            $table->string('title_key', 80);
            $table->string('message_key', 80);
            $table->json('params')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['tenant_id', 'status', 'detected_at']);
            $table->index(['tenant_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lp_alerts');
        Schema::dropIfExists('lp_scenarios');
        Schema::dropIfExists('lp_manual_items');
        Schema::dropIfExists('lp_settings');
    }
};
