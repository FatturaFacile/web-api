<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The register sits on the two contract tables that already exist. Every new column is nullable or has a default,
     * so a contract without notice terms keeps behaving as before.
     */
    public function up(): void
    {
        foreach (['esm_service_contracts', 'supplier_contracts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'auto_renew')) {
                    $table->boolean('auto_renew')->default(false);
                }

                $table->unsignedInteger('notice_period_days')->nullable();
                $table->unsignedInteger('renewal_term_months')->nullable();
                $table->unsignedInteger('alert_days_before')->default(30);
                $table->date('notice_deadline')->nullable()->index();
                $table->timestamp('notice_given_at')->nullable();
            });
        }

        Schema::create('contract_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('contract_kind', 20);
            $table->uuid('contract_id');
            $table->string('alert_type', 30);
            $table->date('due_on');
            $table->string('status', 20)->default('open')->index();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_kind', 'contract_id', 'alert_type', 'due_on'], 'uq_contract_alert');
        });

        Schema::create('contract_renewals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('contract_kind', 20);
            $table->uuid('contract_id');
            $table->date('previous_end_date');
            $table->date('new_end_date');
            $table->string('mode', 20);
            $table->uuid('renewed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_kind', 'contract_id'], 'idx_contract_renewals_contract');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_renewals');
        Schema::dropIfExists('contract_alerts');

        Schema::table('esm_service_contracts', function (Blueprint $table): void {
            $table->dropColumn(['notice_period_days', 'renewal_term_months', 'alert_days_before', 'notice_deadline', 'notice_given_at']);
        });

        Schema::table('supplier_contracts', function (Blueprint $table): void {
            $table->dropColumn(['auto_renew', 'notice_period_days', 'renewal_term_months', 'alert_days_before', 'notice_deadline', 'notice_given_at']);
        });
    }
};
