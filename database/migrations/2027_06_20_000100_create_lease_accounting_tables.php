<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lease accounting of the lessee (IFRS 16, OIC, Swiss GAAP FER): lease contracts, the versions of their schedule
     * (the initial measurement and every remeasurement), the monthly schedule rows of the lease liability and of the
     * right-of-use asset, and the postings of each period. New tables only; the ledger accounts and the journals are
     * referenced by id and checked by the application.
     */
    public function up(): void
    {
        Schema::create('lease_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('title', 190);
            $table->string('lessor_name', 190);
            $table->uuid('lessor_contact_id')->nullable();
            $table->string('asset_class', 30);
            $table->text('asset_description')->nullable();
            $table->uuid('fixed_asset_id')->nullable();
            $table->string('accounting_standard', 20)->default('ifrs16');
            $table->string('currency', 3);
            $table->date('commencement_date');
            $table->unsignedSmallInteger('term_months');
            $table->decimal('payment_amount', 18, 2);
            $table->string('payment_frequency', 20)->default('monthly');
            $table->string('payment_timing', 10)->default('arrears');
            $table->string('escalation_type', 10)->default('none');
            $table->decimal('escalation_rate_percent', 9, 6)->default(0);
            $table->decimal('incremental_borrowing_rate_percent', 9, 6);
            $table->decimal('initial_direct_costs', 18, 2)->default(0);
            $table->decimal('incentives_received', 18, 2)->default(0);
            $table->decimal('purchase_option_price', 18, 2)->nullable();
            $table->boolean('purchase_option_reasonably_certain')->default(false);
            $table->unsignedSmallInteger('extension_months')->nullable();
            $table->boolean('extension_reasonably_certain')->default(false);
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('exemption', 20)->default('none');
            $table->decimal('underlying_asset_value', 18, 2)->nullable();
            $table->uuid('rou_asset_account_id')->nullable();
            $table->uuid('accumulated_depreciation_account_id')->nullable();
            $table->uuid('lease_liability_account_id')->nullable();
            $table->uuid('interest_expense_account_id')->nullable();
            $table->uuid('depreciation_expense_account_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_version_number')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_class']);
            $table->index(['tenant_id', 'commencement_date']);
        });

        Schema::create('lease_schedule_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lease_contract_id')->constrained('lease_contracts')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->string('reason', 20);
            $table->date('effective_date');
            $table->unsignedSmallInteger('start_period_number');
            $table->unsignedSmallInteger('end_period_number');
            $table->decimal('discount_rate_percent', 9, 6);
            $table->decimal('payment_amount', 18, 2);
            $table->decimal('liability_before', 18, 2)->default(0);
            $table->decimal('liability_after', 18, 2);
            $table->decimal('rou_before', 18, 2)->default(0);
            $table->decimal('rou_after', 18, 2);
            $table->decimal('liability_adjustment', 18, 2)->default(0);
            $table->decimal('rou_adjustment', 18, 2)->default(0);
            $table->decimal('result_amount', 18, 2)->default(0);
            $table->decimal('total_undiscounted', 18, 2)->default(0);
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 15)->default('current');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lease_contract_id', 'version_number'], 'lease_versions_contract_version_unique');
            $table->index(['tenant_id', 'lease_contract_id', 'status'], 'lease_versions_contract_status_index');
        });

        Schema::create('lease_schedule_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lease_contract_id')->constrained('lease_contracts')->cascadeOnDelete();
            $table->foreignUuid('schedule_version_id')->constrained('lease_schedule_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_number');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_liability', 18, 2);
            $table->decimal('payment', 18, 2)->default(0);
            $table->decimal('interest', 18, 2)->default(0);
            $table->decimal('principal', 18, 2)->default(0);
            $table->decimal('closing_liability', 18, 2);
            $table->decimal('rou_opening', 18, 2);
            $table->decimal('depreciation', 18, 2)->default(0);
            $table->decimal('rou_closing', 18, 2);
            $table->string('status', 15)->default('scheduled');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lease_contract_id', 'period_number'], 'lease_lines_contract_period_index');
            $table->index(['tenant_id', 'lease_contract_id', 'status'], 'lease_lines_contract_status_index');
            $table->index(['tenant_id', 'schedule_version_id']);
        });

        Schema::create('lease_period_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lease_contract_id')->constrained('lease_contracts')->cascadeOnDelete();
            $table->foreignUuid('schedule_line_id')->constrained('lease_schedule_lines')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_number');
            $table->date('posting_date');
            $table->uuid('journal_entry_id');
            $table->decimal('interest', 18, 2)->default(0);
            $table->decimal('depreciation', 18, 2)->default(0);
            $table->decimal('payment', 18, 2)->default(0);
            $table->timestamp('posted_at');
            $table->uuid('posted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lease_contract_id', 'period_number'], 'lease_postings_contract_period_unique');
            $table->index(['tenant_id', 'posting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_period_postings');
        Schema::dropIfExists('lease_schedule_lines');
        Schema::dropIfExists('lease_schedule_versions');
        Schema::dropIfExists('lease_contracts');
    }
};
