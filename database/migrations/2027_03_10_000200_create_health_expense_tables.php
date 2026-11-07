<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_expense_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('subject_type', 20)->default('professional');
            $table->text('owner_fiscal_code')->nullable();
            $table->string('region_code', 3)->nullable();
            $table->string('asl_code', 3)->nullable();
            $table->string('ssa_code', 6)->nullable();
            $table->timestamps();
        });

        Schema::create('health_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->string('document_kind', 1);
            $table->string('document_number', 40);
            $table->date('issue_date');
            $table->date('payment_date');
            $table->boolean('is_advance_payment')->default(false);
            $table->string('expense_type', 2);
            $table->string('expense_flag', 1)->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('vat_nature', 4)->nullable();
            $table->string('device_number', 3)->default('1');
            $table->text('patient_fiscal_code')->nullable();
            $table->boolean('patient_opposition')->default(false);
            $table->boolean('payment_traceable')->default(false);
            $table->string('operation', 1)->default('I');
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type', 'document_id', 'expense_type'], 'health_expenses_document_unique');
            $table->index(['tenant_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_expenses');
        Schema::dropIfExists('health_expense_settings');
    }
};
