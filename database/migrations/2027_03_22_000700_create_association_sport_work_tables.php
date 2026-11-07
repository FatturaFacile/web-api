<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sports work under Legislative Decree 36/2021: collaborators of the registry, their contracts and the compensations
     * paid, with the contribution and withholding data the payroll consultant computed. Thresholds and rates are legal
     * parameters of the year and are not stored here.
     */
    public function up(): void
    {
        Schema::create('assoc_sport_collaborators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->string('role', 100);
            $table->string('qualification')->nullable();
            $table->uuid('discipline_id')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('insurer')->nullable();
            $table->string('insurance_policy', 100)->nullable();
            $table->date('insurance_expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'member_id']);
            $table->index(['tenant_id', 'insurance_expires_on']);
        });

        Schema::create('assoc_sport_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('collaborator_id')->constrained('assoc_sport_collaborators')->cascadeOnDelete();
            $table->string('kind', 60);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('agreed_amount', 14, 2)->nullable();
            $table->string('agreed_period', 20)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'collaborator_id']);
        });

        Schema::create('assoc_sport_compensations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('collaborator_id')->constrained('assoc_sport_collaborators')->cascadeOnDelete();
            $table->uuid('contract_id')->nullable();
            $table->string('period', 7);
            $table->date('paid_on');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('contribution_base', 14, 2)->nullable();
            $table->decimal('contribution_amount', 14, 2)->nullable();
            $table->decimal('withholding_base', 14, 2)->nullable();
            $table->decimal('withholding_amount', 14, 2)->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'paid_on']);
            $table->index(['tenant_id', 'collaborator_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assoc_sport_compensations ADD CONSTRAINT assoc_sport_compensations_amount_check CHECK (gross_amount > 0)');
            DB::statement("ALTER TABLE assoc_sport_compensations ADD CONSTRAINT assoc_sport_compensations_period_check CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_sport_compensations');
        Schema::dropIfExists('assoc_sport_contracts');
        Schema::dropIfExists('assoc_sport_collaborators');
    }
};
