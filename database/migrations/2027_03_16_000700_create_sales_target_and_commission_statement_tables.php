<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales targets by agent or team, commission statements and their accrual in the ledger. The commissions themselves stay
     * in sales_commissions; a statement gathers those of a period.
     */
    public function up(): void
    {
        Schema::create('sales_teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('sales_representatives', function (Blueprint $table): void {
            $table->foreignUuid('sales_team_id')->nullable()->constrained('sales_teams')->nullOnDelete();
        });

        Schema::create('sales_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sales_representative_id')->nullable()->constrained('sales_representatives')->cascadeOnDelete();
            $table->foreignUuid('sales_team_id')->nullable()->constrained('sales_teams')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_amount', 15, 2);
            $table->string('currency', 3);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'period_start']);
        });

        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_one_owner CHECK ((sales_representative_id IS NULL) <> (sales_team_id IS NULL))');

        Schema::create('sales_commission_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignUuid('payable_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales_commission_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sales_representative_id')->constrained('sales_representatives')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3);
            $table->decimal('total_base', 15, 2);
            $table->decimal('total_commission', 15, 2);
            $table->string('status', 20)->default('draft');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('ledger_status', 20)->default('not_posted');
            $table->uuid('journal_entry_id')->nullable();
            $table->text('ledger_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sales_representative_id', 'period_start', 'period_end'], 'sales_commission_statements_period');
        });

        Schema::table('sales_commissions', function (Blueprint $table): void {
            $table->foreignUuid('commission_statement_id')->nullable()->constrained('sales_commission_statements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_commissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_statement_id');
        });

        Schema::dropIfExists('sales_commission_statements');
        Schema::dropIfExists('sales_commission_settings');
        Schema::dropIfExists('sales_targets');

        Schema::table('sales_representatives', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_team_id');
        });

        Schema::dropIfExists('sales_teams');
    }
};
