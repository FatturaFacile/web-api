<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payslips made available by mail, with only the hash of the link, and the Treasury batches that pay a run.
     */
    public function up(): void
    {
        Schema::create('payroll_payslip_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payslip_id')->constrained('payroll_payslips')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'payslip_id']);
        });

        Schema::create('payroll_payment_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUuid('payment_batch_id')->constrained('payment_batches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'payment_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_batches');
        Schema::dropIfExists('payroll_payslip_deliveries');
    }
};
