<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GH-197: installed base status/site, recurring maintenance contracts with a hour bank
 * (monte-ore) backed by an immutable ledger, and the contract <-> covered asset pivot.
 * Dialect-agnostic: plain columns only (SQLite locally, PostgreSQL in CI/production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('esm_equipment_passports', 'asset_status')) {
            Schema::table('esm_equipment_passports', function (Blueprint $table): void {
                $table->string('asset_status', 30)->default('active')->index(); // active, under_repair, decommissioned
                $table->date('commissioning_date')->nullable();
                $table->string('installation_site', 255)->nullable();
            });
        }

        if (! Schema::hasColumn('esm_service_contracts', 'billing_recurrence')) {
            Schema::table('esm_service_contracts', function (Blueprint $table): void {
                $table->string('billing_recurrence', 20)->default('yearly'); // monthly, quarterly, yearly
                $table->decimal('sla_response_hours', 6, 2)->nullable();
                $table->decimal('included_hours', 10, 2)->default(0);
                $table->decimal('consumed_hours', 10, 2)->default(0);
                $table->unsignedTinyInteger('low_balance_threshold_percent')->default(20);
                $table->string('report_email', 255)->nullable();
                $table->timestamp('last_residual_report_at')->nullable();
                $table->timestamp('low_balance_notified_at')->nullable();
            });
        }

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('esm_work_orders', 'equipment_passport_id')) {
                $table->uuid('equipment_passport_id')->nullable()->index();
            }

            if (! Schema::hasColumn('esm_work_orders', 'service_contract_id')) {
                $table->uuid('service_contract_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('esm_service_contract_assets')) {
            Schema::create('esm_service_contract_assets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('service_contract_id')->constrained('esm_service_contracts')->cascadeOnDelete();
                $table->foreignUuid('equipment_passport_id')->constrained('esm_equipment_passports')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['service_contract_id', 'equipment_passport_id'], 'esm_contract_assets_unique');
                $table->index(['tenant_id', 'equipment_passport_id']);
            });
        }

        if (! Schema::hasTable('esm_contract_hour_transactions')) {
            Schema::create('esm_contract_hour_transactions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('service_contract_id')->constrained('esm_service_contracts')->cascadeOnDelete();
                $table->string('type', 20); // deduction, top_up, adjustment
                $table->decimal('hours', 10, 2); // signed: deduction < 0, top_up > 0
                $table->decimal('balance_after', 10, 2);
                $table->uuid('work_order_id')->nullable()->index();
                $table->uuid('ticket_id')->nullable()->index();
                $table->uuid('equipment_passport_id')->nullable()->index();
                $table->string('technician_name', 150)->nullable();
                $table->string('reference', 120)->nullable();
                $table->string('description', 255)->nullable();
                $table->timestamp('occurred_at');
                $table->uuid('created_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['tenant_id', 'service_contract_id', 'occurred_at'], 'esm_hour_tx_contract_idx');
                $table->unique(['service_contract_id', 'work_order_id', 'type'], 'esm_hour_tx_work_order_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_contract_hour_transactions');
        Schema::dropIfExists('esm_service_contract_assets');

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            $table->dropIndex(['equipment_passport_id']);
            $table->dropIndex(['service_contract_id']);
            $table->dropColumn(['equipment_passport_id', 'service_contract_id']);
        });

        Schema::table('esm_service_contracts', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_recurrence', 'sla_response_hours', 'included_hours', 'consumed_hours',
                'low_balance_threshold_percent', 'report_email', 'last_residual_report_at', 'low_balance_notified_at',
            ]);
        });

        Schema::table('esm_equipment_passports', function (Blueprint $table): void {
            $table->dropColumn(['asset_status', 'commissioning_date', 'installation_site']);
        });
    }
};
