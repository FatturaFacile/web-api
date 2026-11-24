<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company budget by ledger account and cost centre. Project budgets live in the projects module and are unrelated.
     */
    public function up(): void
    {
        Schema::create('gl_budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('fiscal_year_id')->index();
            $table->string('name', 190);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->restrictOnDelete();
            $table->unique(['tenant_id', 'fiscal_year_id', 'name', 'version'], 'uq_gl_budget_version');
        });

        Schema::create('gl_budget_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('budget_id')->index();
            $table->uuid('account_id')->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->unsignedSmallInteger('period_number');
            $table->decimal('amount', 18, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('budget_id')->references('id')->on('gl_budgets')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_gl_budget_line ON gl_budget_lines (budget_id, account_id, COALESCE(cost_center_id, '00000000-0000-0000-0000-000000000000'::uuid), period_number) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_budget_lines');
        Schema::dropIfExists('gl_budgets');
    }
};
