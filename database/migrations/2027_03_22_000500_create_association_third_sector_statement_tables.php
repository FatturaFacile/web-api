<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financial statements of third-sector bodies built from the general ledger. The schemes are fixed by the decree of
     * the Ministry of Labour of 5 March 2020; which account feeds which line is data of each company, in the mapping
     * table, never a constant of the code. A mapping row can be limited to a cost centre, so the same account can feed
     * the general-interest or the secondary section, and can mark the line as commercial for the prevalence check.
     */
    public function up(): void
    {
        Schema::create('assoc_statement_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('scheme', 2);
            $table->string('line_code', 40);
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->uuid('cost_center_id')->nullable();
            $table->boolean('is_commercial')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'scheme']);
        });

        Schema::create('assoc_general_interest_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20)->default('general_interest');
            $table->string('reference', 20);
            $table->string('description', 1000);
            $table->string('statute_clause', 255)->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('assoc_mission_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->jsonb('answers');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'year']);
        });

        Schema::create('assoc_statement_filings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('form', 20);
            $table->string('status', 20)->default('prepared');
            $table->jsonb('snapshot');
            $table->string('content_hash', 64);
            $table->uuid('prepared_by')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->uuid('filed_by')->nullable();
            $table->string('filing_reference', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'year']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX assoc_statement_mappings_unique ON assoc_statement_mappings (tenant_id, scheme, account_id, COALESCE(cost_center_id, '00000000-0000-0000-0000-000000000000')) WHERE deleted_at IS NULL");
            DB::statement("ALTER TABLE assoc_statement_mappings ADD CONSTRAINT assoc_statement_mappings_scheme_check CHECK (scheme IN ('A', 'B', 'D'))");
            DB::statement('CREATE UNIQUE INDEX assoc_mission_reports_year_unique ON assoc_mission_reports (tenant_id, year) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_statement_filings');
        Schema::dropIfExists('assoc_mission_reports');
        Schema::dropIfExists('assoc_general_interest_activities');
        Schema::dropIfExists('assoc_statement_mappings');
    }
};
